<?php

namespace App\Services\Security;

use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\IpGuard;
use Carbon\CarbonImmutable;

/**
 * O handshake TLS de um site, só para ler o certificado e o protocolo: nenhum dado é enviado depois.
 *
 * As travas são as da descoberta: o nome é resolvido aqui, todo IP passa pelo IpGuard e a conexão vai
 * para o IP já validado (com o nome só no SNI e na conferência do certificado), então um DNS que muda
 * no meio do caminho não leva a rede interna. Primeiro conecta conferindo cadeia e nome; se falhar,
 * conecta de novo sem conferir, só para ler o certificado e dizer por que não é confiável.
 */
class TlsProbe
{
    public function __construct(private DnsResolver $dns) {}

    public function inspect(string $host): TlsReport
    {
        $ips = $this->dns->ips($host);

        if ($ips === []) {
            return TlsReport::unreachable("{$host} não resolve no DNS");
        }

        foreach ($ips as $ip) {
            if ($reason = IpGuard::blockReason($ip)) {
                return TlsReport::blocked("{$host} aponta para {$ip} ({$reason})");
            }
        }

        $ip = collect($ips)->first(fn ($i) => filter_var($i, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? $ips[0];
        $timeout = (int) config('secmon.security.timeout', 8);

        [$socket, $problem] = $this->connect($ip, $host, verify: true, timeout: $timeout);
        $trusted = $socket !== null;

        if (! $socket) {
            [$socket] = $this->connect($ip, $host, verify: false, timeout: $timeout);
        }

        if (! $socket) {
            return TlsReport::unreachable('sem resposta TLS na porta 443'.($problem ? ' ('.mb_substr($problem, 0, 80).')' : ''));
        }

        $options = stream_context_get_params($socket)['options']['ssl'] ?? [];
        $protocol = (string) (stream_get_meta_data($socket)['crypto']['protocol'] ?? '') ?: null;
        fclose($socket);

        $report = $this->describe($options['peer_certificate'] ?? null, $protocol, $trusted, $trusted ? null : $this->friendly($problem));

        return $report ?? TlsReport::unreachable('o servidor não apresentou certificado');
    }

    /**
     * O que interessa de um certificado já lido: validade, titular e emissor (só nomes) e a impressão
     * digital. Separado do handshake para testar com um certificado gerado na hora.
     *
     * @param  \OpenSSLCertificate|resource|string|null  $certificate
     */
    public function describe(mixed $certificate, ?string $protocol, bool $trusted, ?string $error = null): ?TlsReport
    {
        if (! $certificate || ! ($parsed = openssl_x509_parse($certificate))) {
            return null;
        }

        return new TlsReport(
            reachable: true,
            trusted: $trusted,
            error: $error,
            notAfter: isset($parsed['validTo_time_t']) ? CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']) : null,
            subject: $this->name($parsed['subject'] ?? []),
            issuer: $this->name($parsed['issuer'] ?? []),
            protocol: $protocol,
            fingerprint: openssl_x509_fingerprint($certificate, 'sha256') ?: null,
        );
    }

    /** @return array{0: ?resource, 1: ?string} o socket (ou nulo) e o motivo da falha */
    private function connect(string $ip, string $host, bool $verify, int $timeout): array
    {
        $address = (str_contains($ip, ':') ? "[{$ip}]" : $ip).':443';
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'SNI_server_name' => $host,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => false,
            'capture_peer_cert' => true,
        ]]);

        $socket = @stream_socket_client("ssl://{$address}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        return $socket ? [$socket, null] : [null, $error ?: "erro {$errno}"];
    }

    /** Mensagem do OpenSSL em uma frase curta. */
    private function friendly(?string $problem): string
    {
        $problem = (string) $problem;

        return match (true) {
            str_contains($problem, 'expired') => 'certificado vencido',
            str_contains($problem, 'self-signed') || str_contains($problem, 'self signed') => 'certificado autoassinado',
            str_contains($problem, 'unable to get local issuer') || str_contains($problem, 'unable to verify') => 'cadeia de certificação incompleta',
            str_contains($problem, 'did not match') || str_contains($problem, 'Peer certificate') => 'o certificado não é para este nome',
            default => 'cadeia não confiável',
        };
    }

    /** @param  array<string, mixed>  $name */
    private function name(array $name): ?string
    {
        $value = $name['CN'] ?? $name['O'] ?? null;

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 120) : null;
    }
}
