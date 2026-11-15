<?php

namespace App\Services\Alerts\Discovery;

use App\Support\RegistrableDomain;

/**
 * Transforma o que foi lido (DNS + uma página) nos sinais que o detector usa:
 * cabeçalhos, cookies, HTML, MX, SPF, TXT, NS, CNAME, <meta generator>,
 * hosts de scripts de terceiros e caminhos de plugins/temas WordPress.
 */
class SignalExtractor
{
    /**
     * @param  array{headers: array, cookies: array, body: string}  $page
     */
    public function seen(string $domain, array $page, array $mx, array $txt, array $ns, array $cname): array
    {
        $html = (string) ($page['body'] ?? '');
        $own = RegistrableDomain::of($domain);

        return [
            'headers' => $page['headers'] ?? [],
            'cookies' => $page['cookies'] ?? [],
            'html' => $html,
            'mx' => array_values(array_unique($mx)),
            'spf' => array_values(array_filter($txt, fn ($t) => str_starts_with(strtolower(trim($t)), 'v=spf1'))),
            'txt' => array_values(array_filter($txt, fn ($t) => ! str_starts_with(strtolower(trim($t)), 'v=spf1'))),
            'ns' => array_values(array_unique($ns)),
            'cname' => array_values(array_unique($cname)),
            'generators' => $this->generators($html),
            // Só scripts e iframes de fora do domínio do cliente: é por eles que
            // um fornecedor comprometido chega ao site (supply chain).
            'scripts' => array_values(array_filter(
                $this->scriptHosts($html),
                fn ($host) => RegistrableDomain::of($host) !== $own,
            )),
            'wordpress' => $this->wordpressItems($html),
        ];
    }

    /** @return list<string> conteúdos de <meta name="generator"> */
    public function generators(string $html): array
    {
        preg_match_all('/<meta\b[^>]*>/i', $html, $tags);
        $found = [];

        foreach ($tags[0] as $tag) {
            if (preg_match('/\bname\s*=\s*["\']?generator["\'\s>]/i', $tag) && preg_match('/\bcontent\s*=\s*["\']([^"\']{1,120})["\']/i', $tag, $m)) {
                $found[] = trim(html_entity_decode($m[1]));
            }
        }

        return array_values(array_unique(array_filter($found)));
    }

    /** @return list<string> hosts de <script src> e <iframe src> absolutos */
    public function scriptHosts(string $html): array
    {
        preg_match_all('/<(?:script|iframe)\b[^>]*?\bsrc\s*=\s*["\']?((?:https?:)?\/\/[^"\'\s>]+)/i', $html, $m);
        $hosts = [];

        foreach ($m[1] as $src) {
            $host = parse_url(str_starts_with($src, '//') ? 'https:'.$src : $src, PHP_URL_HOST);

            if ($host && ! filter_var($host, FILTER_VALIDATE_IP)) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /** @return list<array{type: string, slug: string}> plugins e temas WordPress citados nos assets */
    public function wordpressItems(string $html): array
    {
        preg_match_all('#/wp-content/(plugins|themes)/([a-z0-9][a-z0-9_-]{1,80})/#i', $html, $m, PREG_SET_ORDER);
        $items = [];

        foreach ($m as [, $type, $slug]) {
            $items[strtolower($type.'/'.$slug)] = ['type' => strtolower($type) === 'plugins' ? 'plugin' : 'theme', 'slug' => strtolower($slug)];
        }

        return array_values($items);
    }

    /**
     * Raiz do blog do próprio site, se a página linkar para ele:
     * "/blog/2024/post" → https://site/blog/, "https://blog.site.com.br/x" → https://blog.site.com.br/.
     */
    public function blogUrl(string $html, string $base): ?string
    {
        $origin = parse_url($base);
        $own = RegistrableDomain::of($origin['host'] ?? '');
        preg_match_all('/<a\b[^>]*?\bhref\s*=\s*["\']([^"\'#]+)/i', $html, $m);

        foreach ($m[1] as $href) {
            $href = trim(html_entity_decode($href));
            $url = match (true) {
                str_starts_with($href, '//') => $origin['scheme'].':'.$href,
                str_starts_with($href, '/') => $origin['scheme'].'://'.$origin['host'].$href,
                (bool) preg_match('#^https?://#i', $href) => $href,
                default => null,
            };
            $parts = $url ? parse_url($url) : null;
            $host = strtolower($parts['host'] ?? '');

            if (! $host || RegistrableDomain::of($host) !== $own || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
                continue;
            }

            if (str_starts_with($host, 'blog.')) {
                return strtolower($parts['scheme']).'://'.$host.'/';
            }

            if (preg_match('#^/blog(?:/|$)#i', $parts['path'] ?? '')) {
                return strtolower($parts['scheme']).'://'.$host.'/blog/';
            }
        }

        return null;
    }

    /** Inclusões do SPF: "include:_spf.rdstation.com.br" → "_spf.rdstation.com.br". */
    public static function spfIncludes(array $spf): array
    {
        $hosts = [];

        foreach ($spf as $record) {
            preg_match_all('/\b(?:include|redirect)[:=]([a-z0-9._-]+)/i', $record, $m);
            $hosts = array_merge($hosts, array_map('strtolower', $m[1]));
        }

        return array_values(array_unique($hosts));
    }
}
