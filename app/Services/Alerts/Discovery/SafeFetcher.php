<?php

namespace App\Services\Alerts\Discovery;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Um GET como o de um navegador, com as travas da descoberta:
 *
 * - só http/https, portas 80 e 443, sem usuário:senha na URL;
 * - o host é resolvido aqui e todo IP passa pelo IpGuard antes do request;
 * - o request vai para o IP já validado (CURLOPT_RESOLVE), então um DNS que
 *   muda de resposta no meio do caminho (rebinding) não leva para dentro;
 * - redirects são seguidos à mão, um a um, com a mesma checagem, até 3;
 * - o corpo é lido inteiro, com um teto de segurança (10 MB por padrão);
 * - com escopo (`startScan(..., $allow)`), cada salto só segue para o host e o
 *   IP que o escopo aceita, e URL com IP direto é recusada. Sem escopo (a
 *   descoberta, a monitoria e as imagens do admin), vale qualquer IP público.
 */
class SafeFetcher
{
    /** Teto de requisições da varredura em curso e o que ela já leu (nunca se pede a mesma URL duas vezes). */
    private int $budget = PHP_INT_MAX;

    private int $spent = 0;

    private array $visited = [];

    /** Escopo da varredura em curso; nulo: qualquer IP público (ver startScan). */
    private ?Closure $allow = null;

    public function __construct(private DnsResolver $dns) {}

    /**
     * Começa uma varredura: zera a contagem, a memória de URLs lidas e o escopo.
     *
     * Com `$allow`, cada salto (a URL pedida e cada redirect) só segue se
     * `$allow($host, $ip, $porta)` aceitar o host e o IP em que a conexão vai ser
     * fixada. URL com IP direto é recusada: sem nome, não há o que conferir.
     *
     * @param  ?Closure(string, string, int): bool  $allow
     */
    public function startScan(int $maxRequests, ?Closure $allow = null): void
    {
        $this->budget = max(1, $maxRequests);
        $this->spent = 0;
        $this->visited = [];
        $this->allow = $allow;
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function budget(): int
    {
        return $this->budget;
    }

    /**
     * @param  bool  $expectMissing  404/410 é o esperado (página de erro): loga como info
     * @param  bool  $followRedirects  falso: um 3xx é a resposta final (seus cabeçalhos e cookies valem)
     * @param  ?string  $accept  cabeçalho Accept; o padrão pede HTML (as imagens do admin pedem imagem)
     * @param  ?int  $maxBytes  teto de bytes lidos desta resposta; o padrão é o da descoberta (a monitoria de segurança lê bem menos)
     * @param  ?int  $timeout  segundos de espera desta requisição; o padrão é o da descoberta
     * @return array{ok: bool, blocked: bool, unreachable: bool, status: ?int, url: string, headers: array<string, list<string>>, cookies: list<string>, body: string, bytes: int, error: ?string}
     */
    public function get(string $url, callable $log, bool $expectMissing = false, bool $followRedirects = true, ?string $accept = null, ?int $maxBytes = null, ?int $timeout = null): array
    {
        $max = $maxBytes ?? (int) config('secmon.alerts.discovery.max_bytes', 10 * 1024 * 1024);
        $redirects = (int) config('secmon.alerts.discovery.max_redirects', 3);
        $fail = fn (string $error, bool $blocked = false, bool $unreachable = false) => [
            'ok' => false, 'blocked' => $blocked, 'unreachable' => $unreachable, 'status' => null, 'url' => $url,
            'headers' => [], 'cookies' => [], 'body' => '', 'bytes' => 0, 'truncated' => false, 'error' => $error,
        ];

        for ($hop = 0; $hop <= $redirects; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? '');
            $host = strtolower($parts['host'] ?? '');
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || ! in_array($port, [80, 443], true)) {
                $log("recusado: {$url} (só http/https nas portas 80 e 443)", 'error');

                return $fail('URL fora do permitido', blocked: true);
            }

            $literal = filter_var($host, FILTER_VALIDATE_IP) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);

            if ($literal && $this->allow) {
                $log("recusado: {$url} (IP direto, fora do escopo desta varredura)", 'error');

                return $fail('IP direto fora do escopo', blocked: true);
            }

            if (in_array($url, $this->visited, true)) {
                $log("{$url} já foi lido nesta varredura: não pedimos de novo");

                return $fail('já lido nesta varredura');
            }

            if ($this->spent >= $this->budget) {
                $log("teto de {$this->budget} requisições por varredura: {$url} não foi lido", 'error');

                return $fail('teto de requisições da varredura');
            }

            $ips = $literal ? [trim($host, '[]')] : $this->dns->ips($host);

            if ($ips === []) {
                $log("{$host} não resolve no DNS", 'error');

                return $fail("{$host} não resolve no DNS");
            }

            foreach ($ips as $ip) {
                if ($reason = IpGuard::blockReason($ip)) {
                    $log("{$host} → {$ip} ({$reason}): bloqueado, nenhum request feito", 'error');

                    return $fail("{$host} aponta para {$ip} ({$reason})", blocked: true);
                }
            }

            $ip = collect($ips)->first(fn ($i) => filter_var($i, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? $ips[0];

            if ($this->allow && ! ($this->allow)($host, $ip, (int) $port)) {
                $log("{$host} → {$ip}: fora do escopo desta varredura, nenhum request feito", 'error');

                return $fail("{$host} fora do escopo da varredura", blocked: true);
            }

            $log("{$host} → {$ip} (público)", 'ok');

            $this->spent++;
            $this->visited[] = $url;

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                ])
                    ->withHeaders([
                        'User-Agent' => (string) config('secmon.crawler.user_agent'),
                        'Accept' => $accept ?? 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                    ])
                    ->timeout($timeout ?? (int) config('secmon.alerts.discovery.timeout', 10))
                    ->connectTimeout(5)
                    ->get($url);
            } catch (ConnectionException $e) {
                $log("GET {$url} → sem resposta (".$this->short($e->getMessage()).')', 'error');

                return $fail('sem resposta de '.$host, unreachable: true);
            } catch (Throwable $e) {
                // TLS quebrado e afins: para a descoberta, é como não responder.
                $log("GET {$url} → erro (".$this->short($e->getMessage()).')', 'error');

                return $fail('erro ao acessar '.$host, unreachable: true);
            }

            $status = $response->status();

            if ($followRedirects && $status >= 300 && $status < 400 && ($location = $response->header('Location'))) {
                $next = $this->absolute($url, $location);
                $log("GET {$url} → {$status} {$next}", 'info');
                $url = $next;

                continue;
            }

            [$body, $truncated] = $this->readLimited($response, $max);
            $headers = [];

            foreach ($response->headers() as $name => $values) {
                $headers[strtolower($name)] = array_values((array) $values);
            }

            $cookies = array_values(array_unique(array_filter(array_map(
                fn ($c) => trim(explode('=', $c, 2)[0]),
                $headers['set-cookie'] ?? [],
            ))));

            $log(sprintf(
                'GET %s → %d · %s KB lidos (%s)',
                $url,
                $status,
                number_format(strlen($body) / 1024, 0, ',', '.'),
                $truncated ? 'cortado no teto de '.($max >= 1048576 ? number_format($max / 1048576, 0, ',', '.').' MB' : number_format($max / 1024, 0, ',', '.').' KB') : 'página inteira',
            ), $status < 400 || ($expectMissing && in_array($status, [404, 410], true)) ? 'info' : 'error');

            return [
                'ok' => $status < 400, 'blocked' => false, 'unreachable' => false, 'status' => $status, 'url' => $url,
                'headers' => $headers, 'cookies' => $cookies, 'body' => $body, 'bytes' => strlen($body), 'truncated' => $truncated,
                'error' => $status < 400 ? null : "HTTP {$status}",
            ];
        }

        $log('redirects demais (mais de '.$redirects.')', 'error');

        return $fail('redirects demais');
    }

    /** @return array{0: string, 1: bool} corpo e se foi cortado no teto */
    private function readLimited($response, int $max): array
    {
        try {
            $stream = $response->toPsrResponse()->getBody();

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $body = '';

            // Lê um byte além do teto para saber se a página acabou ou foi cortada.
            while (! $stream->eof() && strlen($body) <= $max) {
                $chunk = $stream->read(min(65536, $max + 1 - strlen($body)));

                if ($chunk === '') {
                    break;
                }

                $body .= $chunk;
            }

            $truncated = strlen($body) > $max;

            // Passou do teto: corta o download em vez de ler o resto.
            if ($truncated) {
                $stream->close();
            }

            return [substr($body, 0, $max), $truncated];
        } catch (Throwable) {
            return ['', false];
        }
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';

        return $origin.$dir.$location;
    }

    private function short(string $message): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $message), 0, 120);
    }
}
