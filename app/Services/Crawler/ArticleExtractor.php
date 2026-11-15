<?php

namespace App\Services\Crawler;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;

/**
 * Entra na página da matéria e extrai o texto principal.
 *
 * A maioria das fontes só publica um resumo no RSS (verificado: securityonline,
 * theregister, zdnet…), então sem esta etapa o enriquecimento ficaria pobre.
 *
 * A ordem importa: primeiro tenta o `articleBody` do JSON-LD (quando existe é o
 * texto limpo do artigo), depois escolhe o container por densidade de
 * parágrafos. O ruído por classe é filtrado *na leitura*, nunca removendo nós
 * do DOM — remover um ancestral cuja classe casa com "share"/"related" apagava
 * a matéria inteira em blogs.cisco.com, unit42 e proofpoint.
 */
class ArticleExtractor
{
    /** Tags que nunca contêm o corpo da matéria. */
    private const NOISE_TAGS = [
        'script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside',
        'form', 'iframe', 'svg', 'button',
    ];

    /** Fragmentos de classe/id que marcam blocos periféricos. */
    private const NOISE_CLASSES = [
        'related', 'newsletter', 'sidebar', 'advert', 'promo', 'cookie',
        'social-share', 'share-buttons', 'sharebar', 'comment-list', 'comments-area',
        'breadcrumb', 'subscribe', 'popup', 'modal', 'nav-', 'menu-', 'footer',
    ];

    private const CONTAINER_HINTS = [
        "//*[@itemprop='articleBody']",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' entry-content ')]",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' post-content ')]",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' article-content ')]",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' article-body ')]",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' single-content ')]",
        '//article',
        '//main',
    ];

    private const MIN_USEFUL_CHARS = 320;

    /** Imagens do corpo que a leitura por IA aceita olhar (HTML colado no admin). */
    private const MAX_IMAGES = 8;

    /** Imagem com esses termos no src/classe é logo, avatar ou ícone, não figura da matéria. */
    private const IMAGE_NOISE = ['logo', 'avatar', 'gravatar', 'icon', 'sprite', 'pixel', 'badge', 'emoji'];

    public function __construct(private CrawlerHttp $http = new CrawlerHttp) {}

    /**
     * Com `$proxy`, a página é baixada pelo proxy da fonte (veja CrawlerHttp).
     *
     * @return array{title:?string,text:string,published_at:?Carbon,author:?string,description:?string,ok:bool,error:?string}
     */
    public function extract(string $url, ?string $proxy = null): array
    {
        $empty = [
            'title' => null, 'text' => '', 'published_at' => null,
            'author' => null, 'description' => null, 'ok' => false, 'error' => null,
        ];

        try {
            $response = $this->http->request($proxy)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->retry(2, 800, throw: false)
                ->get($url);
        } catch (\Throwable $e) {
            return [...$empty, 'error' => CrawlerHttp::redact($e->getMessage())];
        }

        if (! $response->successful()) {
            // 403/429 costuma ser WAF anti-bot (darkreading, por exemplo). O
            // artigo ainda é salvo com o resumo do feed, mas o motivo fica
            // registrado para aparecer no painel de coletas.
            return [...$empty, 'error' => "HTTP {$response->status()}"];
        }

        return $this->fromHtml($response->body());
    }

    /**
     * Lê um HTML já em mãos: o que o crawler baixou ou o que o admin colou.
     * Com `$withImages`, devolve também as URLs absolutas das imagens do corpo
     * (resolvidas contra `$baseUrl`), para a IA decidir quais fazem parte do post.
     *
     * @return array{title:?string,text:string,published_at:?Carbon,author:?string,description:?string,ok:bool,error:?string,images?:list<string>}
     */
    public function fromHtml(string $html, ?string $baseUrl = null, bool $withImages = false): array
    {
        $empty = [
            'title' => null, 'text' => '', 'published_at' => null,
            'author' => null, 'description' => null, 'ok' => false, 'error' => null,
        ] + ($withImages ? ['images' => []] : []);

        if (trim($html) === '') {
            return [...$empty, 'error' => 'corpo vazio'];
        }

        $dom = $this->parse($html);

        if (! $dom) {
            return [...$empty, 'error' => 'HTML ilegível'];
        }

        $xpath = new DOMXPath($dom);
        $meta = $this->meta($xpath);

        [$blockText, $block] = $this->mainBlock($xpath);
        $text = $this->structuredBody($xpath) ?: $blockText;

        $limit = (int) config('secmon.crawler.max_content_chars');

        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit);
        }

        $ok = mb_strlen($text) >= self::MIN_USEFUL_CHARS;

        return [
            'title' => $meta['title'],
            'text' => $text,
            'published_at' => $meta['published_at'],
            'author' => $meta['author'],
            'description' => $meta['description'],
            'ok' => $ok,
            'error' => $ok ? null : 'texto principal muito curto',
        ] + ($withImages ? ['images' => $this->images($xpath, $block, $baseUrl)] : []);
    }

    private function parse(string $html): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;

        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        } catch (\Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $dom : null;
    }

    /**
     * schema.org/Article → `articleBody`. Quando presente, é o texto do artigo
     * já sem menu, rodapé e recomendação — inclusive em páginas que montam o
     * corpo por JavaScript.
     */
    private function structuredBody(DOMXPath $xpath): string
    {
        $nodes = $xpath->query("//script[@type='application/ld+json']");

        if (! $nodes) {
            return '';
        }

        $best = '';

        foreach ($nodes as $node) {
            $data = json_decode(trim((string) $node->textContent), true);

            if (! is_array($data)) {
                continue;
            }

            foreach ($this->findArticleBodies($data) as $body) {
                $body = trim((string) preg_replace("/[ \t]+/u", ' ', $body));

                if (mb_strlen($body) > mb_strlen($best)) {
                    $best = $body;
                }
            }
        }

        return mb_strlen($best) >= self::MIN_USEFUL_CHARS ? $best : '';
    }

    /** @return list<string> */
    private function findArticleBodies(array $data): array
    {
        $found = [];

        foreach ($data as $key => $value) {
            if ($key === 'articleBody' && is_string($value)) {
                $found[] = $value;
            } elseif (is_array($value)) {
                $found = array_merge($found, $this->findArticleBodies($value));
            }
        }

        return $found;
    }

    /**
     * Escolhe o container com maior massa de texto em parágrafos diretos e lê
     * o conteúdo dele. É a heurística clássica de readability.
     */
    /**
     * Mesma escolha, devolvendo também o container de onde o texto saiu (é
     * nele que se procuram as imagens da matéria).
     *
     * @return array{0: string, 1: ?DOMElement}
     */
    private function mainBlock(DOMXPath $xpath): array
    {
        $best = '';
        $bestNode = null;

        foreach (self::CONTAINER_HINTS as $query) {
            foreach ($this->elements($xpath, $query) as $node) {
                $text = $this->readableText($xpath, $node);

                if (mb_strlen($text) > mb_strlen($best)) {
                    [$best, $bestNode] = [$text, $node];
                }
            }

            if (mb_strlen($best) >= 800) {
                return [$best, $bestNode];
            }
        }

        if (mb_strlen($best) >= self::MIN_USEFUL_CHARS) {
            return [$best, $bestNode];
        }

        // Nenhum container conhecido: procura o bloco mais denso da página.
        if ($densest = $this->densestBlock($xpath)) {
            $text = $this->readableText($xpath, $densest);

            if (mb_strlen($text) > mb_strlen($best)) {
                [$best, $bestNode] = [$text, $densest];
            }
        }

        if (mb_strlen($best) < self::MIN_USEFUL_CHARS) {
            foreach ($this->elements($xpath, '//body') as $body) {
                $text = $this->readableText($xpath, $body);

                if (mb_strlen($text) > mb_strlen($best)) {
                    [$best, $bestNode] = [$text, $body];
                }
            }
        }

        return [$best, $bestNode];
    }

    /**
     * Imagens candidatas a figura da matéria: a og:image e as <img> do corpo,
     * fora dos blocos periféricos, sem ícone/logo/avatar nem SVG. Quem decide
     * se cada uma faz parte do post é a IA (ReadArticleMedia).
     *
     * @return list<string>
     */
    private function images(DOMXPath $xpath, ?DOMElement $container, ?string $baseUrl): array
    {
        $candidates = [];

        foreach ($this->elements($xpath, "//meta[@property='og:image']") as $og) {
            $candidates[] = $og->getAttribute('content');
        }

        $scope = $container ?? ($this->elements($xpath, '//body')[0] ?? null);

        foreach ($scope ? ($xpath->query('.//img', $scope) ?: []) : [] as $img) {
            if (! $img instanceof DOMElement || $this->insideNoise($img, $scope)) {
                continue;
            }

            $width = (int) $img->getAttribute('width');
            $height = (int) $img->getAttribute('height');

            if (($width > 0 && $width < 120) || ($height > 0 && $height < 120)) {
                continue;
            }

            $src = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src')
                ?: $this->largestFromSrcset($img->getAttribute('srcset')) ?: $img->getAttribute('src');
            $marker = mb_strtolower($src.' '.$img->getAttribute('class').' '.$img->getAttribute('alt'));

            foreach (self::IMAGE_NOISE as $needle) {
                if (str_contains($marker, $needle)) {
                    continue 2;
                }
            }

            $candidates[] = $src;
        }

        $urls = [];

        foreach ($candidates as $src) {
            $src = trim((string) $src);

            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $url = self::absoluteUrl($baseUrl, $src);

            if ($url === null || preg_match('/\.svg(\?|#|$)/i', $url)) {
                continue;
            }

            $urls[$url] = true;

            if (count($urls) >= self::MAX_IMAGES) {
                break;
            }
        }

        return array_keys($urls);
    }

    private function largestFromSrcset(string $srcset): string
    {
        $best = ['', 0];

        foreach (array_filter(array_map('trim', explode(',', $srcset))) as $entry) {
            [$url, $size] = array_pad(preg_split('/\s+/', $entry, 2), 2, '');
            $width = (int) $size;

            if ($url !== '' && $width >= $best[1]) {
                $best = [$url, $width];
            }
        }

        return $best[0];
    }

    /** Resolve src relativo (`/img/a.png`, `img/a.png`, `//cdn/a.png`) contra a URL da matéria. */
    public static function absoluteUrl(?string $base, string $src): ?string
    {
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }

        if (str_starts_with($src, '//')) {
            return 'https:'.$src;
        }

        $parts = $base ? parse_url($base) : false;

        if (! $parts || empty($parts['host'])) {
            return null;
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($src, '/')) {
            return $origin.$src;
        }

        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') ?: '/';

        return $origin.$dir.$src;
    }

    private function densestBlock(DOMXPath $xpath): ?DOMElement
    {
        $nodes = $xpath->query('//div[p] | //section[p] | //article[p] | //main[p] | //td[p]');

        if (! $nodes) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement || $this->isNoise($node)) {
                continue;
            }

            $score = 0;

            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->nodeName === 'p') {
                    $score += mb_strlen(trim((string) $child->textContent));
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $node;
            }
        }

        return $bestScore >= self::MIN_USEFUL_CHARS ? $best : null;
    }

    /** Lê parágrafos, títulos, listas e blocos de código, pulando o periférico. */
    private function readableText(DOMXPath $xpath, DOMElement $container): string
    {
        $nodes = $xpath->query('.//p|.//h2|.//h3|.//li|.//pre', $container);

        if (! $nodes) {
            return '';
        }

        $chunks = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement || $this->insideNoise($node, $container)) {
                continue;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));

            if (mb_strlen($text) >= 25) {
                $chunks[$text] = true;
            }
        }

        return implode("\n\n", array_keys($chunks));
    }

    /** O nó (ou algum ancestral até o container) é um bloco periférico? */
    private function insideNoise(DOMNode $node, DOMElement $container): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            if ($current->isSameNode($container)) {
                return false;
            }

            if (in_array($current->nodeName, self::NOISE_TAGS, true) || $this->isNoise($current)) {
                return true;
            }
        }

        return false;
    }

    private function isNoise(DOMElement $element): bool
    {
        $marker = mb_strtolower($element->getAttribute('class').' '.$element->getAttribute('id'));

        if ($marker === ' ') {
            return false;
        }

        foreach (self::NOISE_CLASSES as $needle) {
            if (str_contains($marker, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<DOMElement> */
    private function elements(DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);

        if (! $nodes) {
            return [];
        }

        $elements = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function meta(DOMXPath $xpath): array
    {
        $get = function (array $queries) use ($xpath): ?string {
            foreach ($queries as $query) {
                $nodes = $xpath->query($query);

                if ($nodes && $nodes->length > 0) {
                    $value = trim((string) $nodes->item(0)->nodeValue);

                    if ($value !== '') {
                        return $value;
                    }
                }
            }

            return null;
        };

        $published = $get([
            "//meta[@property='article:published_time']/@content",
            "//meta[@name='article:published_time']/@content",
            "//meta[@property='og:published_time']/@content",
            "//meta[@name='date']/@content",
            "//meta[@itemprop='datePublished']/@content",
            '//time/@datetime',
        ]);

        try {
            $publishedAt = $published ? Carbon::parse($published)->utc() : null;
        } catch (\Throwable) {
            $publishedAt = null;
        }

        return [
            'title' => $get([
                "//meta[@property='og:title']/@content",
                "//meta[@name='twitter:title']/@content",
                '//h1',
                '//title',
            ]),
            'description' => $get([
                "//meta[@property='og:description']/@content",
                "//meta[@name='description']/@content",
            ]),
            'author' => $get([
                "//meta[@name='author']/@content",
                "//meta[@property='article:author']/@content",
                "//*[@rel='author']",
            ]),
            'published_at' => $publishedAt,
        ];
    }
}
