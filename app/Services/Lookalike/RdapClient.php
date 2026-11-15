<?php

namespace App\Services\Lookalike;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Registro público (RDAP, o sucessor do WHOIS): quando o domínio foi criado,
 * por qual registrador, e a quem denunciar abuso no registro e na hospedagem.
 *
 * O servidor de cada terminação e de cada faixa de IP vem do bootstrap da
 * IANA. Dado pessoal do titular (nome, e-mail, documento) nunca é guardado.
 */
class RdapClient
{
    /** Os domínios de uma vez (em paralelo): host => dados (vazio se o RDAP não respondeu). */
    public function domains(array $hosts): array
    {
        $urls = [];

        foreach ($hosts as $host) {
            $tld = substr(strrchr($host, '.'), 1);

            if ($base = $this->serverFor('dns', fn (array $keys) => in_array($tld, $keys, true))) {
                $urls[$host] = $base.'domain/'.$host;
            }
        }

        $out = array_fill_keys($hosts, []);

        foreach ($this->fetchAll($urls) as $host => $data) {
            $out[$host] = $data ? $this->parseDomain($data, (string) parse_url($urls[$host], PHP_URL_HOST)) : [];
        }

        return $out;
    }

    /** @return array{registered_at?: string, expires_at?: string, registrar?: string, abuse_email?: string, server?: string} */
    public function domain(string $host): array
    {
        return $this->domains([$host])[$host] ?? [];
    }

    /**
     * Dono da faixa de cada IP (a hospedagem) e o e-mail de abuso dela, em paralelo: ip => dados.
     * Com `$timeout` (a classificação da stack de testes, que roda dentro do prazo do passo), cada
     * consulta tem esse tempo e segue no máximo 2 redirecionamentos, só por HTTPS.
     */
    public function ips(array $ips, ?int $timeout = null): array
    {
        $urls = [];

        foreach ($ips as $ip) {
            $registry = str_contains($ip, ':') ? 'ipv6' : 'ipv4';

            if ($base = $this->serverFor($registry, fn (array $keys) => collect($keys)->contains(fn ($cidr) => $this->inCidr($ip, $cidr)))) {
                $urls[$ip] = $base.'ip/'.$ip;
            }
        }

        $out = array_fill_keys($ips, []);

        foreach ($this->fetchAll($urls, $timeout) as $ip => $data) {
            $out[$ip] = $data ? $this->parseIp($data, $ip) : [];
        }

        return $out;
    }

    public function ip(string $ip): array
    {
        return $this->ips([$ip])[$ip] ?? [];
    }

    private function parseDomain(array $data, string $server): array
    {
        $out = ['server' => $server];

        foreach ($data['events'] ?? [] as $event) {
            match ($event['eventAction'] ?? null) {
                'registration' => $out['registered_at'] = $event['eventDate'] ?? null,
                'expiration' => $out['expires_at'] = $event['eventDate'] ?? null,
                default => null,
            };
        }

        foreach ($data['entities'] ?? [] as $entity) {
            if (in_array('registrar', $entity['roles'] ?? [], true)) {
                $out['registrar'] = $this->vcard($entity, 'fn');
                $out['abuse_email'] = $this->abuseEmail($entity['entities'] ?? []);
            }
        }

        $out['abuse_email'] ??= $this->abuseEmail($data['entities'] ?? []);

        // No .br o registro é o próprio Registro.br, e golpe se reporta ao CERT.br.
        if (str_contains($server, 'registro.br')) {
            $out['registrar'] = 'Registro.br';
            $out['abuse_email'] = 'cert@cert.br';
        }

        return array_filter($out);
    }

    private function parseIp(array $data, string $ip): array
    {
        // O titular da faixa ("Cloudflare, Inc."); o contato administrativo só se não houver titular.
        $org = null;

        foreach (['registrant', 'administrative'] as $role) {
            foreach ($data['entities'] ?? [] as $entity) {
                if (in_array($role, $entity['roles'] ?? [], true) && ($org = $this->vcard($entity, 'fn'))) {
                    break 2;
                }
            }
        }

        return array_filter([
            'ip' => $ip,
            'network' => $data['name'] ?? null,
            'org' => $org,
            'abuse_email' => $this->abuseEmail($data['entities'] ?? []),
        ]);
    }

    /**
     * Várias consultas ao mesmo tempo, poucas por vez: os servidores de RDAP
     * (o do Registro.br em especial) limitam quem pergunta demais.
     *
     * @param  array<string, string>  $urls  chave => URL
     * @return array<string, ?array>
     */
    private function fetchAll(array $urls, ?int $timeout = null): array
    {
        if ($urls === []) {
            return [];
        }

        $headers = ['Accept' => 'application/rdap+json, application/json;q=0.9', 'User-Agent' => (string) config('secmon.crawler.user_agent')];
        $redirects = $timeout === null ? [] : ['allow_redirects' => ['max' => 2, 'strict' => true, 'referer' => false, 'protocols' => ['https']]];
        $timeout ??= (int) config('secmon.lookalikes.timeout', 20);

        $responses = Http::pool(fn (Pool $pool) => collect($urls)
            ->map(fn (string $url, string $key) => $pool->as($key)->timeout($timeout)->withOptions($redirects)->withHeaders($headers)->get($url))
            ->values()
            ->all(), concurrency: 6);

        $out = [];

        foreach ($urls as $key => $url) {
            $response = $responses[$key] ?? null;
            $out[$key] = $response instanceof Response && $response->successful() && is_array($json = $response->json()) ? $json : null;
        }

        return $out;
    }

    private function serverFor(string $registry, callable $matches): ?string
    {
        $services = Cache::remember("lookalike:rdap-bootstrap:{$registry}", now()->addDays(7), function () use ($registry) {
            $data = $this->get(rtrim((string) config('secmon.lookalikes.rdap_bootstrap_url'), '/')."/{$registry}.json", 'application/json');

            return $data['services'] ?? null;
        });

        foreach ($services ?? [] as [$keys, $urls]) {
            if ($matches(array_map('strtolower', $keys))) {
                $https = collect($urls)->first(fn ($u) => str_starts_with($u, 'https://')) ?? $urls[0] ?? null;

                return $https ? rtrim($https, '/').'/' : null;
            }
        }

        if ($services === null) {
            Cache::forget("lookalike:rdap-bootstrap:{$registry}");
        }

        return null;
    }

    private function get(string $url, string $accept = 'application/rdap+json'): ?array
    {
        try {
            $response = Http::timeout((int) config('secmon.lookalikes.timeout', 20))
                ->withHeaders(['Accept' => $accept.', application/json;q=0.9', 'User-Agent' => (string) config('secmon.crawler.user_agent')])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() && is_array($json = $response->json()) ? $json : null;
    }

    private function abuseEmail(array $entities): ?string
    {
        foreach ($entities as $entity) {
            if (in_array('abuse', $entity['roles'] ?? [], true) && ($email = $this->vcard($entity, 'email'))) {
                return strtolower($email);
            }

            if ($nested = $this->abuseEmail($entity['entities'] ?? [])) {
                return $nested;
            }
        }

        return null;
    }

    private function vcard(array $entity, string $field): ?string
    {
        foreach ($entity['vcardArray'][1] ?? [] as $entry) {
            if (($entry[0] ?? null) === $field && is_string($entry[3] ?? null) && trim($entry[3]) !== '') {
                return trim($entry[3]);
            }
        }

        return null;
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);

        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if (strncmp($ipBin, $netBin, $bytes) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($netBin[$bytes]) & ord($mask));
    }
}
