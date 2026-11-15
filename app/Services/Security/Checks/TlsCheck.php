<?php

namespace App\Services\Security\Checks;

use App\Services\Security\CheckResult;
use App\Services\Security\SiteProbe;
use Carbon\CarbonImmutable;

/** Certificado confiável, quanto falta para vencer e a versão do TLS negociada. */
class TlsCheck implements SiteCheck
{
    public function ids(): array
    {
        return ['tls.valid', 'tls.expiry', 'tls.protocol'];
    }

    public function run(SiteProbe $probe): array
    {
        $tls = $probe->tls();

        if ($tls->blocked) {
            return [
                CheckResult::untested('tls.valid', $tls->blocked),
                CheckResult::untested('tls.expiry', 'nenhuma conexão foi aberta'),
                CheckResult::untested('tls.protocol', 'nenhuma conexão foi aberta'),
            ];
        }

        if (! $tls->reachable) {
            // Sem TLS na porta 443: o site não serve HTTPS (ou está fora do ar; a página inicial diz qual).
            return [
                CheckResult::critical('tls.valid', (string) $tls->error),
                CheckResult::untested('tls.expiry', 'sem certificado para ler'),
                CheckResult::untested('tls.protocol', 'sem handshake'),
            ];
        }

        return [
            $tls->trusted
                ? CheckResult::ok('tls.valid', trim(($tls->issuer ? 'emitido por '.$tls->issuer : 'cadeia confiável')))
                : CheckResult::critical('tls.valid', (string) ($tls->error ?: 'cadeia não confiável')),
            $this->expiry($tls->notAfter),
            $this->protocol($tls->protocol),
        ];
    }

    private function expiry(?CarbonImmutable $notAfter): CheckResult
    {
        if (! $notAfter) {
            return CheckResult::untested('tls.expiry', 'o certificado não informou a validade');
        }

        // Dias inteiros que faltam (negativo: já venceu).
        $days = (int) floor(now()->diffInSeconds($notAfter, false) / 86400);

        return match (true) {
            $days < 0 => CheckResult::critical('tls.expiry', 'venceu há '.abs($days).' dia(s)'),
            $days <= 7 => CheckResult::critical('tls.expiry', "vence em {$days} dia(s)"),
            $days <= 14 => CheckResult::warning('tls.expiry', "vence em {$days} dia(s)"),
            default => CheckResult::ok('tls.expiry', "vence em {$days} dias ({$notAfter->timezone('America/Sao_Paulo')->format('d/m/Y')})"),
        };
    }

    private function protocol(?string $protocol): CheckResult
    {
        return match (true) {
            $protocol === null || $protocol === '' => CheckResult::untested('tls.protocol', 'o servidor não informou'),
            in_array($protocol, ['TLSv1.3', 'TLSv1.2'], true) => CheckResult::ok('tls.protocol', $protocol),
            default => CheckResult::critical('tls.protocol', "negocia {$protocol}"),
        };
    }
}
