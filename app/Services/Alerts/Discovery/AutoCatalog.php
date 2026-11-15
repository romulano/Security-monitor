<?php

namespace App\Services\Alerts\Discovery;

use App\Models\TechCatalog;
use App\Support\RegistrableDomain;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sinal que nenhuma regra do catálogo reconhece vira item do catálogo, para
 * ser monitorado: um provedor de e-mail no MX, um include de SPF, um script
 * de terceiro, um plugin WordPress... Uma notícia sobre ele pode sair amanhã.
 *
 * O item nasce com a regra de detecção que o encontrou (outros clientes com o
 * mesmo sinal já o reconhecem) e como `strict`: só alerta quando a análise o
 * lista como produto afetado ou fornecedor, nunca por menção solta no texto —
 * o nome vem de um domínio ou cabeçalho e pode ser genérico. Fica marcado como
 * `origin = discovery` para o admin revisar nome e termos.
 */
class AutoCatalog
{
    /** Rótulos que, sozinhos, casariam com notícia demais: o termo fica só o domínio. */
    private const GENERIC_LABELS = [
        'google', 'googleapis', 'gstatic', 'googleusercontent', 'amazon', 'amazonaws', 'microsoft', 'azure', 'office',
        'outlook', 'live', 'windows', 'apple', 'icloud', 'facebook', 'fbcdn', 'meta', 'yahoo', 'akamai', 'cloud', 'cdn',
        'static', 'assets', 'api', 'app', 'apps', 'mail', 'email', 'smtp', 'dns', 'web', 'site', 'online', 'server', 'host',
    ];

    /**
     * Domínios compartilhados por muitos clientes. Num MX/NS/CNAME o sufixo é o
     * fornecedor ("loja.netlify.app" → netlify.app); num script o host não diz
     * de quem é o código ("d1234.cloudfront.net"), então não vira item.
     */
    private const SHARED_SUFFIXES = [
        'cloudfront.net', 'amazonaws.com', 'azureedge.net', 'azurewebsites.net', 'blob.core.windows.net', 'herokuapp.com',
        'netlify.app', 'vercel.app', 'github.io', 'gitlab.io', 'appspot.com', 'firebaseapp.com', 'web.app', 'pages.dev',
        'workers.dev', 'b-cdn.net', 'akamaized.net', 'edgekey.net', 'edgesuite.net', 'fastly.net', 'global.ssl.fastly.net',
    ];

    private const HEADERS = ['server' => 'webserver', 'x-powered-by' => 'framework', 'x-generator' => 'cms'];

    public function __construct(private StackDetector $detector) {}

    /**
     * Sinais que nenhuma regra reconhece, já no formato de item do catálogo.
     *
     * @return list<array{slug: string, name: string, category: string, terms: list<string>, rule: array, evidence: string, parent: ?string}>
     */
    public function candidates(array $seen, string $domain): array
    {
        $own = RegistrableDomain::of($domain);
        $all = TechCatalog::query()->get();
        $catalog = $all->filter(fn (TechCatalog $t) => $t->detect !== []);
        // Nomes e termos já catalogados ("cloudflare"): o mesmo produto visto por outro sinal não vira item novo.
        $names = $all->flatMap(fn (TechCatalog $t) => array_merge([mb_strtolower($t->name)], $t->terms ?? []))->map(fn ($n) => mb_strtolower($n))->flip();
        $found = [];

        $add = function (string $kind, string $key, string $name, string $category, array $terms, array $rule, array $mini, string $evidence, ?string $parent = null) use (&$found, $catalog, $names, $all) {
            $slug = 'auto-'.Str::limit(Str::slug($kind.'-'.$key), 70, '');
            $sameName = collect(array_merge([$name], $terms))->contains(fn ($t) => $names->has(mb_strtolower($t)));

            if (isset($found[$slug]) || $sameName || $all->contains('slug', $slug) || $this->known($mini, $catalog, $parent)) {
                return;
            }

            $found[$slug] = compact('slug', 'name', 'category', 'terms', 'rule', 'evidence', 'parent');
        };

        foreach ($seen['mx'] ?? [] as $host) {
            if (($reg = $this->provider($host, $own)) !== null) {
                $add('mx', $reg, $reg, 'identity', $this->terms($reg), ['type' => 'mx', 'pattern' => $reg], ['mx' => [$host]], "MX {$host}");
            }
        }

        foreach (SignalExtractor::spfIncludes($seen['spf'] ?? []) as $include) {
            if (($reg = $this->provider($include, $own)) !== null) {
                $add('spf', $reg, $reg, 'email', $this->terms($reg), ['type' => 'spf', 'pattern' => $reg], ['spf' => ["v=spf1 include:{$include}"]], "SPF include:{$include}");
            }
        }

        foreach ($seen['ns'] ?? [] as $host) {
            if (($reg = $this->provider($host, $own)) !== null) {
                $add('ns', $reg, $reg, 'dns', $this->terms($reg), ['type' => 'ns', 'pattern' => $reg], ['ns' => [$host]], "NS {$host}");
            }
        }

        foreach ($seen['cname'] ?? [] as $target) {
            if (($reg = $this->provider($target, $own)) !== null) {
                $add('cname', $reg, $reg, 'cloud', $this->terms($reg), ['type' => 'cname', 'pattern' => $reg], ['cname' => [$target]], "CNAME {$target}");
            }
        }

        foreach ($seen['txt'] ?? [] as $record) {
            // "atlassian-domain-verification=…", "zoom_verify_…". Prova de posse de
            // plataforma genérica ("google-site-verification") não diz que produto é usado.
            if (preg_match('/^\s*([a-z0-9][a-z0-9.-]{1,40}?)[-_](?:site[-_]|domain[-_])?(?:verification|verify)\b/i', $record, $m)
                && ! str_starts_with(strtolower($m[1]), 'secmon') && ! in_array(strtolower($m[1]), self::GENERIC_LABELS, true)) {
                $service = strtolower($m[1]);
                $add('txt', $service, Str::headline($service), 'thirdparty', $this->terms($service), ['type' => 'txt', 'pattern' => strtolower($m[0])], ['txt' => [$record]], 'TXT '.$m[0].'…');
            }
        }

        foreach (self::HEADERS as $header => $category) {
            foreach ($seen['headers'][$header] ?? [] as $value) {
                foreach ($this->productTokens($header, $value) as [$product, $token]) {
                    $add('header-'.$header, $product, $product, $category, $this->terms(mb_strtolower($product)),
                        ['type' => 'header', 'name' => $header, 'pattern' => 're:'.preg_quote($product).'(?:[/ ]v?([\d.]+))?'],
                        ['headers' => [$header => [$token]]], Str::headline($header).': '.$value);
                }
            }
        }

        foreach ($seen['generators'] ?? [] as $generator) {
            $product = trim(preg_replace('/(\s+v?\d[\w.\-]*)+\s*$/i', '', $generator));

            if (mb_strlen($product) >= 2) {
                $add('generator', $product, $product, 'cms', $this->terms(mb_strtolower($product)),
                    ['type' => 'generator', 'pattern' => $product],
                    ['generators' => [$generator], 'html' => '<meta name="generator" content="'.e($generator).'">'], "meta generator \"{$generator}\"");
            }
        }

        foreach ($seen['scripts'] ?? [] as $host) {
            $key = RegistrableDomain::of($host);

            if ($key && $key !== $own && $this->sharedSuffix($host) === null) {
                $add('script', $key, $key, 'thirdparty', $this->terms($key), ['type' => 'script', 'pattern' => $key],
                    ['scripts' => [$host], 'html' => 'src="https://'.$host.'/'], "script de {$host}");
            }
        }

        foreach ($seen['wordpress'] ?? [] as $item) {
            $path = '/wp-content/'.($item['type'] === 'plugin' ? 'plugins' : 'themes').'/'.$item['slug'].'/';
            $words = trim(str_replace(['-', '_'], ' ', $item['slug']));
            $label = 'WordPress '.($item['type'] === 'plugin' ? 'plugin' : 'tema').': '.Str::title($words);

            $add('wp-'.$item['type'], $item['slug'], $label, 'cms', array_values(array_unique([$words, $item['slug']])),
                ['type' => 'html', 'pattern' => $path], ['html' => $path], "HTML contém {$path}", 'wordpress');
        }

        return array_values($found);
    }

    /**
     * Cadastra os candidatos no catálogo (até o teto por execução).
     *
     * @return Collection<int, TechCatalog>
     */
    public function register(array $seen, string $domain): Collection
    {
        $limit = (int) config('secmon.alerts.discovery.max_auto_items', 60);

        return collect($this->candidates($seen, $domain))->take($limit)->map(fn (array $c) => TechCatalog::query()->create([
            'slug' => $c['slug'],
            'name' => Str::limit($c['name'], 80, ''),
            'category' => $c['category'],
            'parent_id' => $c['parent'] ? TechCatalog::query()->where('slug', $c['parent'])->value('id') : null,
            'terms' => $c['terms'],
            'detect' => [$c['rule']],
            'strict' => true,
            'active' => true,
            'origin' => 'discovery',
            'discovered_on' => $domain,
            'position' => 900,
        ]));
    }

    /**
     * Alguma regra do catálogo (ativa ou não) já explica este sinal? A do item
     * pai não conta: "/wp-content/" diz que é WordPress, não qual plugin.
     */
    private function known(array $mini, Collection $catalog, ?string $parent = null): bool
    {
        $seen = $mini + ['headers' => [], 'cookies' => [], 'html' => '', 'mx' => [], 'spf' => [], 'txt' => [], 'ns' => [], 'cname' => [], 'generators' => [], 'scripts' => []];

        return collect($this->detector->detect($seen, $catalog))->contains(fn (array $hit) => $hit['tech']->slug !== $parent);
    }

    /** Domínio do fornecedor por trás de um host; nulo se for do próprio cliente. */
    private function provider(string $host, ?string $own): ?string
    {
        $key = $this->sharedSuffix($host) ?? RegistrableDomain::of($host);

        return $key && $key !== $own ? $key : null;
    }

    private function sharedSuffix(string $host): ?string
    {
        foreach (self::SHARED_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($host), '.'.$suffix)) {
                return $suffix;
            }
        }

        return null;
    }

    /** "rdstation.com.br" → ["rdstation", "rdstation.com.br"]. Rótulo genérico fica só com o todo. */
    private function terms(string $key): array
    {
        $label = str_contains($key, '.') ? RegistrableDomain::label($key) : $key;

        return array_values(array_unique(strlen($label) >= 4 && ! in_array($label, self::GENERIC_LABELS, true) ? [$label, $key] : [$key]));
    }

    /**
     * Server lista vários produtos: "Apache/2.4.58 (Ubuntu) OpenSSL/3.0.2" →
     * Apache, Ubuntu, OpenSSL. X-Powered-By e X-Generator são um produto só:
     * "Phusion Passenger 6.0.17" → "Phusion Passenger".
     *
     * @return list<array{0: string, 1: string}> produto e o trecho que o mostra
     */
    private function productTokens(string $header, string $value): array
    {
        if ($header !== 'server') {
            $product = trim(preg_replace('/([\s\/]+v?\d[\w.\-]*)+\s*$/i', '', $value));

            return preg_match('/^[A-Za-z][A-Za-z0-9 ._+-]{1,60}$/', $product) ? [[$product, $value]] : [];
        }

        $tokens = [];

        foreach (preg_split('/[\s,;()]+/', $value) as $token) {
            $product = explode('/', $token)[0];

            if (preg_match('/^[A-Za-z][A-Za-z0-9._+-]{1,40}$/', $product)) {
                $tokens[] = [$product, $token];
            }
        }

        return $tokens;
    }
}
