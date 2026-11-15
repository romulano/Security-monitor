<?php

namespace App\Services\Lookalike;

use App\Support\RegistrableDomain;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Certificados públicos (Certificate Transparency) com o nome da marca, pelo
 * crt.sh. Pega o que nenhuma lista de variações prevê ("fatalmodel-verificacao.top").
 *
 * O crt.sh cai com frequência (502). Falha não derruba a varredura: as
 * variações seguem, e o motivo vai para o log que o cliente vê.
 */
class CertificateSearch
{
    /**
     * @param  list<string>  $exclude  domínios registráveis do próprio cliente
     * @return array{hosts: list<string>, error: ?string, retry: bool} `retry`: o erro é do crt.sh (vale tentar de novo), não do nome
     */
    public function search(string $term, array $exclude = []): array
    {
        $term = strtolower(trim($term));

        if (strlen($term) < 5) {
            // Termo curto casa com metade da internet ("acme" está em milhares de nomes).
            return ['hosts' => [], 'error' => 'nome curto demais para buscar em certificados', 'retry' => false];
        }

        try {
            $response = Http::timeout((int) config('secmon.lookalikes.timeout', 20) * 3)
                ->withHeaders(['User-Agent' => (string) config('secmon.crawler.user_agent')])
                ->retry(2, 2000, throw: false)
                ->get(rtrim((string) config('secmon.lookalikes.crtsh_url'), '/').'/', [
                    'q' => '%'.$term.'%',
                    'output' => 'json',
                    'exclude' => 'expired',
                ]);
        } catch (Throwable $e) {
            return ['hosts' => [], 'error' => 'crt.sh sem resposta', 'retry' => true];
        }

        if (! $response->successful() || ! is_array($rows = $response->json())) {
            return ['hosts' => [], 'error' => 'crt.sh indisponível (HTTP '.$response->status().')', 'retry' => true];
        }

        $excluded = array_flip($exclude);
        $hosts = [];

        foreach ($rows as $row) {
            foreach (preg_split('/\s+/', (string) ($row['name_value'] ?? '')) as $name) {
                $name = strtolower(ltrim(trim($name), '*.'));

                if ($name === '' || ! str_contains($name, $term)) {
                    continue;
                }

                // O que importa é o domínio registrável ("login.fatalmodel-app.com" → "fatalmodel-app.com"),
                // menos quando a marca está só no subdomínio de outro domínio ("fatalmodel.conta-segura.com").
                $registrable = RegistrableDomain::of($name);

                if (! $registrable || isset($excluded[$registrable])) {
                    continue;
                }

                $hosts[str_contains($registrable, $term) ? $registrable : $name] = true;
            }
        }

        return ['hosts' => array_keys($hosts), 'error' => null, 'retry' => false];
    }

    /**
     * Os subdomínios de um domínio nos certificados públicos (`%.domínio`), para o
     * recon passivo da stack de testes (06 §3.1, item `sup.ct_subdominios`): só os
     * nomes de `*.domínio`, sem os vencidos. O curinga (`*.x.domínio`) não vira nome:
     * marca a cobertura curinga em `x.domínio` (há nomes que não vemos, L6). O nome do
     * domínio vai ao crt.sh, um serviço de terceiro (a /privacidade diz isso).
     *
     * @return array{hosts: list<string>, wildcards: list<string>, truncated: bool, error: ?string}
     */
    public function subdomains(string $domain, int $max): array
    {
        $domain = strtolower(trim($domain));

        try {
            // Sem seguir redirect: a URL é fixa, e um salto para dentro (o crt.sh comprometido) não vira pedido do servidor.
            $response = Http::timeout((int) config('secmon.lookalikes.timeout', 20) * 3)
                ->withHeaders(['User-Agent' => (string) config('secmon.crawler.user_agent')])
                ->withoutRedirecting()
                ->retry(2, 2000, throw: false)
                ->get(rtrim((string) config('secmon.lookalikes.crtsh_url'), '/').'/', [
                    'q' => '%.'.$domain,
                    'output' => 'json',
                    'exclude' => 'expired',
                ]);
        } catch (Throwable) {
            return ['hosts' => [], 'wildcards' => [], 'truncated' => false, 'error' => 'crt.sh sem resposta'];
        }

        // O corpo grande demais não chega ao json_decode (que gastaria várias vezes o tamanho em memória).
        if ($response->successful() && strlen($response->body()) > (int) config('secmon.pentest.recon.max_ct_bytes', 16 * 1024 * 1024)) {
            return ['hosts' => [], 'wildcards' => [], 'truncated' => false, 'error' => 'crt.sh: resposta grande demais'];
        }

        if (! $response->successful() || ! is_array($rows = $response->json())) {
            return ['hosts' => [], 'wildcards' => [], 'truncated' => false, 'error' => 'crt.sh indisponível (HTTP '.$response->status().')'];
        }

        $hosts = [];
        $wildcards = [];

        foreach ($rows as $row) {
            foreach (preg_split('/\s+/', is_array($row) ? (string) ($row['name_value'] ?? '') : '') as $name) {
                $name = rtrim(strtolower(trim($name)), '.');
                $wildcard = str_starts_with($name, '*.');
                $name = $wildcard ? substr($name, 2) : $name;

                // Só nomes de DNS de verdade, dentro do domínio (o crt.sh devolve também o que só contém o termo).
                if ($name === '' || strlen($name) > 253 || ($name !== $domain && ! str_ends_with($name, '.'.$domain)) || ! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+\z/', $name)) {
                    continue;
                }

                if ($wildcard) {
                    $wildcards[$name] = true;
                } else {
                    $hosts[$name] = true;
                }
            }
        }

        $names = array_keys($hosts);
        sort($names);
        $wildcards = array_keys($wildcards);
        sort($wildcards);

        return ['hosts' => array_slice($names, 0, $max), 'wildcards' => $wildcards, 'truncated' => count($names) > $max, 'error' => null];
    }
}
