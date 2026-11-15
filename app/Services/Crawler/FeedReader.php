<?php

namespace App\Services\Crawler;

use Illuminate\Support\Carbon;
use RuntimeException;
use SimpleXMLElement;

/**
 * Lê um feed RSS 2.0 ou Atom e devolve FeedItem[]. É deliberadamente tolerante:
 * feeds reais vêm com XML mal formado, datas em formatos variados e namespaces
 * inconsistentes. Quando o site trocou o RSS por um índice JSON dos posts, lê
 * esse índice (veja parseIndex).
 */
class FeedReader
{
    public function __construct(private CrawlerHttp $http = new CrawlerHttp) {}

    /**
     * Com `$proxy`, o feed é baixado pelo proxy da fonte (veja CrawlerHttp).
     * O que vem depois do `#` no endereço nunca vai ao site: no índice JSON é
     * a seção que interessa (`.../blog-index.json#/blog/threat-intelligence/`).
     */
    public function fetch(string $feedUrl, int $limit = 25, ?string $proxy = null): array
    {
        [$address, $section] = array_pad(explode('#', $feedUrl, 2), 2, '');

        $response = $this->http->request($proxy)
            ->withHeaders(['Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, application/json;q=0.9, */*;q=0.8'])
            ->retry(2, 500, throw: false)
            ->get($address);

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} ao buscar o feed");
        }

        $body = $response->body();

        if (str_starts_with(ltrim($body), '{')) {
            return $this->parseIndex($body, $address, $section, $limit);
        }

        return $this->parse($body, $limit);
    }

    /**
     * O índice JSON que os sites no Adobe Edge Delivery publicam no lugar do
     * RSS (o da Infoblox, desde que o /blog/feed/ dela sumiu):
     * `{"data": [{"path", "title", "description", "publishedDate", "category"}]}`.
     * Ele traz o blog inteiro e fora de ordem: `$section` deixa só os caminhos
     * que começam com ela, e os mais novos vêm primeiro.
     */
    public function parseIndex(string $json, string $indexUrl, string $section = '', int $limit = 25): array
    {
        $rows = json_decode($json, true);
        $rows = is_array($rows) ? ($rows['data'] ?? null) : null;

        if (! is_array($rows)) {
            throw new RuntimeException('Índice JSON inválido');
        }

        $parts = parse_url($indexUrl);
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        $items = [];

        foreach ($rows as $row) {
            $path = is_array($row) ? trim((string) ($row['path'] ?? '')) : '';
            $title = is_array($row) ? trim((string) ($row['title'] ?? '')) : '';

            // Só caminhos do próprio site: nada de endereço absoluto vindo do JSON.
            if (! str_starts_with($path, '/') || str_starts_with($path, '//') || ! str_starts_with($path, $section) || $title === '') {
                continue;
            }

            $items[] = new FeedItem(
                url: $this->cleanUrl($base.$path),
                title: html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                guid: $path,
                publishedAt: $this->timestamp($row['publishedDate'] ?? null),
                description: $this->plain((string) ($row['description'] ?? '')),
                categories: array_values(array_filter([trim((string) ($row['category'] ?? ''))])),
            );
        }

        // Os mais novos primeiro; sem data, no fim.
        usort($items, fn (FeedItem $a, FeedItem $b) => ($b->publishedAt?->getTimestamp() ?? 0) <=> ($a->publishedAt?->getTimestamp() ?? 0));

        return array_slice($items, 0, max(1, $limit));
    }

    public function parse(string $xml, int $limit = 25): array
    {
        $xml = trim($xml);

        if ($xml === '') {
            throw new RuntimeException('Feed vazio');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($root === false) {
            throw new RuntimeException('XML inválido');
        }

        $items = [];

        // RSS 2.0 / RDF
        foreach ($root->channel->item ?? [] as $node) {
            $items[] = $this->fromRss($node);
        }

        if ($items === []) {
            foreach ($root->item ?? [] as $node) {
                $items[] = $this->fromRss($node);
            }
        }

        // Atom
        if ($items === []) {
            foreach ($root->entry ?? [] as $node) {
                $items[] = $this->fromAtom($node);
            }
        }

        $items = array_values(array_filter($items, fn (?FeedItem $i) => $i !== null && $i->url !== ''));

        // Nem todo feed vem do mais novo para o mais velho (o da CERT-FR mistura
        // as datas, e o da F5 traz centenas de itens): cortar na ordem do arquivo
        // deixava de fora os avisos novos que vinham depois do limite. A ordem é
        // estável: itens com a mesma data, ou sem data (que vão para o fim),
        // ficam na ordem do feed.
        usort($items, fn (FeedItem $a, FeedItem $b) => ($b->publishedAt?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->publishedAt?->getTimestamp() ?? PHP_INT_MIN));

        return array_slice($items, 0, max(1, $limit));
    }

    private function fromRss(SimpleXMLElement $node): ?FeedItem
    {
        $dc = $node->children('http://purl.org/dc/elements/1.1/');

        $url = trim((string) $node->link);

        if ($url === '') {
            $guidNode = $node->guid ?? null;
            if ($guidNode && filter_var((string) $guidNode, FILTER_VALIDATE_URL)) {
                $url = (string) $guidNode;
            }
        }

        if ($url === '') {
            return null;
        }

        $categories = [];
        foreach ($node->category ?? [] as $category) {
            $categories[] = trim((string) $category);
        }

        return new FeedItem(
            url: $this->cleanUrl($url),
            title: html_entity_decode(trim((string) $node->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            guid: trim((string) ($node->guid ?? '')) ?: null,
            author: trim((string) ($dc->creator ?? $node->author ?? '')) ?: null,
            publishedAt: $this->date((string) ($node->pubDate ?? $dc->date ?? '')),
            description: $this->plain((string) ($node->description ?? '')),
            categories: array_values(array_filter($categories)),
        );
    }

    private function fromAtom(SimpleXMLElement $node): ?FeedItem
    {
        $url = '';

        foreach ($node->link ?? [] as $link) {
            $rel = (string) ($link['rel'] ?? 'alternate');

            if ($rel === 'alternate' || $rel === '') {
                $url = (string) $link['href'];
                break;
            }
        }

        if ($url === '') {
            $url = trim((string) ($node->id ?? ''));
        }

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $categories = [];
        foreach ($node->category ?? [] as $category) {
            $categories[] = trim((string) ($category['term'] ?? ''));
        }

        return new FeedItem(
            url: $this->cleanUrl($url),
            title: html_entity_decode(trim((string) $node->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            guid: trim((string) ($node->id ?? '')) ?: null,
            author: trim((string) ($node->author->name ?? '')) ?: null,
            publishedAt: $this->date((string) ($node->published ?? $node->updated ?? '')),
            description: $this->plain((string) ($node->summary ?? $node->content ?? '')),
            categories: array_values(array_filter($categories)),
        );
    }

    private function date(string $raw): ?Carbon
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** No índice JSON a data é um timestamp Unix (em segundos ou milissegundos). */
    private function timestamp(mixed $raw): ?Carbon
    {
        if (! is_numeric($raw)) {
            return is_string($raw) ? $this->date($raw) : null;
        }

        $seconds = (float) $raw > 1e11 ? (float) $raw / 1000 : (float) $raw;

        return $seconds > 0 ? Carbon::createFromTimestampUTC((int) $seconds) : null;
    }

    private function plain(string $html): ?string
    {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return $text !== '' ? $text : null;
    }

    /** Remove parâmetros de rastreio para não gerar duplicata por URL. */
    private function cleanUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! $parts || empty($parts['host'])) {
            return trim($url);
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        foreach (array_keys($query) as $key) {
            if (preg_match('/^(utm_.*|fbclid|gclid|mc_cid|mc_eid|ref|source)$/i', (string) $key)) {
                unset($query[$key]);
            }
        }

        $rebuilt = ($parts['scheme'] ?? 'https').'://'.$parts['host'];

        if (! empty($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }

        $rebuilt .= $parts['path'] ?? '';

        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query);
        }

        return $rebuilt;
    }
}
