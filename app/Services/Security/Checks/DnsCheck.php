<?php

namespace App\Services\Security\Checks;

use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Security\CheckResult;
use App\Services\Security\SiteProbe;
use App\Support\RegistrableDomain;

/** SPF e DMARC do domínio principal (o e-mail do site sai em nome dele). Só leitura de DNS. */
class DnsCheck implements SiteCheck
{
    public function __construct(private DnsResolver $dns) {}

    public function ids(): array
    {
        return ['dns.spf', 'dns.dmarc'];
    }

    public function run(SiteProbe $probe): array
    {
        $domain = RegistrableDomain::of($probe->host()) ?? $probe->host();

        return [$this->spf($domain), $this->dmarc($domain)];
    }

    private function spf(string $domain): CheckResult
    {
        $records = array_values(array_filter(
            array_map('trim', $this->dns->txt($domain)),
            fn (string $txt) => preg_match('/^v=spf1(\s|$)/i', $txt) === 1,
        ));

        if ($records === []) {
            return CheckResult::warning('dns.spf', "sem SPF em {$domain}");
        }

        if (count($records) > 1) {
            return CheckResult::critical('dns.spf', count($records).' registros SPF: o receptor descarta todos');
        }

        $spf = strtolower($records[0]);

        return match (true) {
            (bool) preg_match('/(^|\s)\+?all(\s|$)/', $spf) => CheckResult::critical('dns.spf', '+all autoriza qualquer servidor a enviar'),
            str_contains($spf, '-all') => CheckResult::ok('dns.spf', '-all (rejeita o que não está na lista)'),
            str_contains($spf, '~all') => CheckResult::ok('dns.spf', '~all (marca como suspeito o que não está na lista)'),
            default => CheckResult::warning('dns.spf', 'não termina em -all nem ~all'),
        };
    }

    private function dmarc(string $domain): CheckResult
    {
        $record = collect($this->dns->txt('_dmarc.'.$domain))
            ->map(fn ($txt) => trim($txt))
            ->first(fn (string $txt) => preg_match('/^v=DMARC1\b/i', $txt) === 1);

        if (! $record) {
            return CheckResult::warning('dns.dmarc', "sem DMARC em _dmarc.{$domain}");
        }

        preg_match('/\bp\s*=\s*(none|quarantine|reject)\b/i', $record, $m);
        $policy = strtolower($m[1] ?? 'none');

        return $policy === 'none'
            ? CheckResult::warning('dns.dmarc', 'p=none: só observa, não protege')
            : CheckResult::ok('dns.dmarc', "p={$policy}");
    }
}
