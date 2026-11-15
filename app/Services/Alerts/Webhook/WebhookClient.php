<?php

namespace App\Services\Alerts\Webhook;

use App\Models\NotificationChannel;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\IpGuard;
use App\Support\IpList;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Entrega o JSON no webhook do cliente. O endereço é de terceiro, então vale
 * tudo o que segura um SSRF:
 *
 * - só sai para URL https:443 de domínio que a conta comprovou (WebhookOwnership),
 *   conferido de novo aqui, e nunca para o próprio Security Monitor (WebhookUrl);
 * - o host é resolvido aqui e todo IP passa pelo IpGuard (rede interna, loopback,
 *   link-local e metadata da nuvem, IPv6 local) e pela lista dos endereços do
 *   próprio Security Monitor (secmon.own_addresses); a conexão vai para o IP já conferido
 *   (CURLOPT_RESOLVE), então um DNS que muda no meio (rebinding) não leva para dentro;
 * - não segue redirecionamento (um 3xx é falha), espera no máximo 3 s pela conexão e
 *   10 s pela resposta, e lê só os primeiros 4 KB do corpo, que descarta: vale o código.
 *
 * Cabeçalhos: o User-Agent do Security Monitor (o mesmo do robô), Security-Monitor-Event e Security-Monitor-Delivery
 * e, com a autenticação ligada, Security-Monitor-Notification (o segredo) e Security-Monitor-Signature
 * (HMAC do corpo com o segredo, ver WebhookSecret). Nem a URL nem o segredo vão para
 * log ou para a mensagem de erro.
 */
class WebhookClient
{
    public function __construct(private DnsResolver $dns, private WebhookOwnership $ownership) {}

    public function post(NotificationChannel $channel, array $payload): WebhookResult
    {
        if (! $channel->isWebhook() || ! $this->ownership->holds($channel)) {
            return WebhookResult::permanent(0, 'unproven', breaksChannel: true);
        }

        try {
            ['url' => $url, 'host' => $host] = WebhookUrl::normalize($channel->webhook_url);
        } catch (InvalidArgumentException) {
            return WebhookResult::permanent(0, 'invalid_url', breaksChannel: true);
        }

        if ($host !== $channel->webhook_host) {
            return WebhookResult::permanent(0, 'invalid_url', breaksChannel: true);
        }

        $ips = $this->dns->ipsOrNull($host);

        if (! $ips) {
            return WebhookResult::transient(0, 'dns');
        }

        foreach ($ips as $ip) {
            if ($reason = self::blockReason($ip)) {
                return WebhookResult::permanent(0, 'blocked', detail: $reason);
            }
        }

        $ip = collect($ips)->first(fn ($i) => filter_var($i, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? $ips[0];
        $now = now();
        $body = self::encode($stamped = self::stamp($payload, $now));

        try {
            $response = Http::withOptions(self::options($host, $ip))
                ->connectTimeout((int) config('secmon.alerts.webhook.connect_timeout', 3))
                ->timeout((int) config('secmon.alerts.webhook.timeout', 10))
                ->withHeaders(self::headers($channel, $stamped, $body, $now->getTimestamp()))
                ->withBody($body, 'application/json; charset=utf-8')
                ->post($url);
        } catch (ConnectionException $e) {
            return WebhookResult::transient(0, preg_match('/timed? ?out|cURL error 28/i', $e->getMessage()) ? 'timeout' : 'connection');
        } catch (Throwable) {
            return WebhookResult::transient(0, 'connection');
        }

        self::drain($response);
        $status = $response->status();

        return match (true) {
            $status >= 200 && $status < 300 => WebhookResult::ok($status),
            $status === 429 => WebhookResult::rateLimited($status, self::retryAfter($response->header('Retry-After'))),
            $status >= 300 && $status < 400 => WebhookResult::permanent($status, 'redirect'),
            $status === 410 => WebhookResult::permanent($status, 'gone', breaksChannel: true),
            $status >= 400 && $status < 500 => WebhookResult::permanent($status, 'refused'),
            default => WebhookResult::transient($status, 'server'),
        };
    }

    /**
     * O envelope como sai: a ordem fixa (o jsonb do histórico não guarda a ordem das chaves) e o
     * `sent_at` desta tentativa. O `id` é o da entrega: igual em todas as tentativas, para o receptor deduplicar.
     */
    public static function stamp(array $payload, Carbon $now): array
    {
        return [
            'version' => $payload['version'] ?? MessageBuilder::VERSION,
            'id' => $payload['id'] ?? null,
            'event' => $payload['event'] ?? null,
            'sent_at' => $now->copy()->utc()->toIso8601ZuluString(),
            'data' => $payload['data'] ?? [],
        ];
    }

    /** Os bytes que vão no corpo e na assinatura. */
    public static function encode(array $stamped): string
    {
        return json_encode($stamped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    public static function headers(NotificationChannel $channel, array $stamped, string $body, int $timestamp): array
    {
        $headers = [
            'User-Agent' => (string) config('secmon.crawler.user_agent'),
            'Security-Monitor-Event' => (string) ($stamped['event'] ?? ''),
            'Security-Monitor-Delivery' => (string) ($stamped['id'] ?? ''),
        ];

        if ($channel->signs()) {
            $secret = (string) $channel->webhook_secret;
            $headers['Security-Monitor-Notification'] = $secret;
            $headers['Security-Monitor-Signature'] = WebhookSecret::signature($secret, $timestamp, $body);
        }

        return $headers;
    }

    /** Sem redirecionamento, o corpo lido aos poucos e a conexão presa ao IP conferido. */
    public static function options(string $host, string $ip): array
    {
        return [
            'allow_redirects' => false,
            'stream' => true,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:443:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
        ];
    }

    /** Nulo se o Security Monitor pode chamar o IP; senão, o motivo. */
    public static function blockReason(string $ip): ?string
    {
        return IpGuard::blockReason($ip)
            ?? (IpList::contains(IpList::ipsAndRanges((array) config('secmon.own_addresses', [])), $ip) ? 'endereço do próprio Security Monitor' : null);
    }

    /** Segundos do Retry-After (número ou data HTTP), entre 1 s e o teto da configuração; sem ele, 1 minuto. */
    public static function retryAfter(?string $header): int
    {
        $header = trim((string) $header);
        $seconds = match (true) {
            ctype_digit($header) => (int) $header,
            $header !== '' && ($at = strtotime($header)) !== false => $at - now()->getTimestamp(),
            default => 60,
        };

        return max(1, min($seconds, (int) config('secmon.alerts.webhook.max_retry_after', 1800)));
    }

    /** Lê até o teto e fecha: a resposta não é guardada, e um corpo enorme não prende o worker. */
    private static function drain(Response $response): void
    {
        try {
            $stream = $response->toPsrResponse()->getBody();
            $left = (int) config('secmon.alerts.webhook.max_response_bytes', 4096);

            while ($left > 0 && ! $stream->eof()) {
                $chunk = $stream->read(min(1024, $left));

                if ($chunk === '') {
                    break;
                }

                $left -= strlen($chunk);
            }

            $stream->close();
        } catch (Throwable) {
            // O código já veio; o corpo não importa.
        }
    }
}
