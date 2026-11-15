<?php

namespace Tests\Feature\Crawler;

use App\Services\Crawler\FeedReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** A porta de entrada dos feeds das fontes: RSS, Atom, o índice JSON do site e o que vier mal formado. */
class FeedReaderTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): FeedReader
    {
        return new FeedReader;
    }

    private function rss(string $items): string
    {
        return '<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>F</title>'.$items.'</channel></rss>';
    }

    public function test_fetch_sends_the_crawler_identity_and_parses_the_feed(): void
    {
        Http::fake(['feed.test/rss' => Http::response($this->rss('<item><title>A</title><link>https://feed.test/a</link></item>'))]);

        $items = $this->reader()->fetch('https://feed.test/rss');

        $this->assertCount(1, $items);
        Http::assertSent(function (Request $request) {
            $this->assertNotSame('', $request->header('User-Agent')[0]);
            $this->assertStringContainsString('application/rss+xml', $request->header('Accept')[0]);

            return true;
        });
    }

    public function test_fetch_fails_with_the_status_when_the_feed_does_not_answer_ok(): void
    {
        Http::fake(['feed.test/*' => Http::response('erro', 503)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503 ao buscar o feed');

        $this->reader()->fetch('https://feed.test/rss');
    }

    public function test_rss_items_carry_title_author_date_description_and_categories(): void
    {
        $items = $this->reader()->parse($this->rss(
            '<item><title>Acme &amp;amp; Co sofre ataque</title><link>https://feed.test/a?utm_source=x&amp;id=7&amp;fbclid=y</link>'
            .'<guid>g-1</guid><dc:creator>Maria</dc:creator><pubDate>Fri, 23 Oct 2026 10:00:00 -0300</pubDate>'
            .'<description><![CDATA[<p>Resumo <b>com</b> HTML</p>]]></description>'
            .'<category>Ransomware</category><category> Finance </category><category></category></item>'
        ));

        $item = $items[0];
        $this->assertSame('https://feed.test/a?id=7', $item->url, 'rastreio sai, o resto fica');
        $this->assertSame('Acme & Co sofre ataque', $item->title);
        $this->assertSame('g-1', $item->guid);
        $this->assertSame('Maria', $item->author);
        $this->assertSame('2026-10-23 13:00:00', $item->publishedAt->format('Y-m-d H:i:s'), 'a data vai para UTC');
        $this->assertSame('Resumo com HTML', $item->description);
        $this->assertSame(['Ransomware', 'Finance'], $item->categories);
    }

    public function test_an_rss_item_without_a_link_falls_back_to_a_guid_that_is_a_url(): void
    {
        $items = $this->reader()->parse($this->rss(
            '<item><title>Com guid</title><guid>https://feed.test/pelo-guid</guid></item>'
            .'<item><title>Guid que não é URL</title><guid>abc-123</guid></item>'
            .'<item><title>Sem nada</title></item>'
        ));

        $this->assertSame(['https://feed.test/pelo-guid'], array_map(fn ($i) => $i->url, $items));
    }

    public function test_the_limit_keeps_the_first_items_and_never_drops_below_one(): void
    {
        $xml = $this->rss(implode('', array_map(fn ($n) => "<item><title>T{$n}</title><link>https://feed.test/{$n}</link></item>", range(1, 5))));

        $this->assertSame(['https://feed.test/1', 'https://feed.test/2'], array_map(fn ($i) => $i->url, $this->reader()->parse($xml, 2)));
        $this->assertCount(1, $this->reader()->parse($xml, 0));
    }

    public function test_a_feed_out_of_order_keeps_the_newest_items_inside_the_limit(): void
    {
        // Como o da CERT-FR: os avisos novos vêm depois de itens antigos no arquivo.
        $item = fn ($n, $date) => "<item><title>T{$n}</title><link>https://feed.test/{$n}</link>".($date ? "<pubDate>{$date}</pubDate>" : '').'</item>';
        $xml = $this->rss(
            $item(1, 'Mon, 02 Nov 2026 10:00:00 GMT')
            .$item(2, 'Wed, 04 Nov 2026 10:00:00 GMT')
            .$item(3, null)
            .$item(4, 'Fri, 06 Nov 2026 10:00:00 GMT')
            .$item(5, 'Wed, 04 Nov 2026 10:00:00 GMT')
            .$item(6, 'Fri, 30 Oct 2026 10:00:00 GMT')
        );

        $urls = fn (int $limit) => array_map(fn ($i) => $i->url, $this->reader()->parse($xml, $limit));

        $this->assertSame(['https://feed.test/4', 'https://feed.test/2', 'https://feed.test/5'], $urls(3));
        // O sem data vai para o fim; os de mesma data ficam na ordem do feed.
        $this->assertSame(['https://feed.test/4', 'https://feed.test/2', 'https://feed.test/5', 'https://feed.test/1', 'https://feed.test/6', 'https://feed.test/3'], $urls(10));
    }

    public function test_rdf_feeds_with_items_at_the_root_are_read(): void
    {
        $xml = '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<item><title>RDF</title><link>https://feed.test/rdf</link><dc:date>2026-10-22T08:00:00Z</dc:date></item></rdf:RDF>';

        $items = $this->reader()->parse($xml);

        $this->assertSame('https://feed.test/rdf', $items[0]->url);
        $this->assertSame('2026-10-22', $items[0]->publishedAt?->format('Y-m-d'));
    }

    public function test_atom_entries_use_the_alternate_link_and_fall_back_to_the_id(): void
    {
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>A</title>'
            .'<entry><title>Com link</title><id>tag:feed.test,1</id>'
            .'<link rel="self" href="https://feed.test/self"/><link rel="alternate" href="https://feed.test/alt?utm_medium=z"/>'
            .'<author><name>João</name></author><published>2026-10-21T09:30:00Z</published><updated>2026-10-22T00:00:00Z</updated>'
            .'<summary>Resumo &lt;b&gt;atom&lt;/b&gt;</summary><category term="Malware"/><category term=""/></entry>'
            .'<entry><title>Só o id</title><id>https://feed.test/pelo-id</id><content>Conteúdo</content></entry>'
            .'<entry><title>Sem endereço</title><id>urn:uuid:123</id></entry></feed>';

        $items = $this->reader()->parse($xml);

        $this->assertCount(2, $items);
        $this->assertSame('https://feed.test/alt', $items[0]->url);
        $this->assertSame('João', $items[0]->author);
        $this->assertSame('2026-10-21 09:30:00', $items[0]->publishedAt->format('Y-m-d H:i:s'), 'published vence updated');
        $this->assertSame('Resumo atom', $items[0]->description);
        $this->assertSame(['Malware'], $items[0]->categories);
        $this->assertSame('https://feed.test/pelo-id', $items[1]->url);
        $this->assertSame('Conteúdo', $items[1]->description);
    }

    public function test_a_date_nobody_can_parse_becomes_null_instead_of_breaking_the_feed(): void
    {
        $items = $this->reader()->parse($this->rss('<item><title>T</title><link>https://feed.test/a</link><pubDate>ontem de manhã</pubDate></item>'));

        $this->assertNull($items[0]->publishedAt);
    }

    public function test_an_empty_or_broken_feed_is_reported(): void
    {
        $this->assertSame('Feed vazio', $this->messageOf(fn () => $this->reader()->parse("  \n ")));
        $this->assertSame('XML inválido', $this->messageOf(fn () => $this->reader()->parse('<rss><channel><item>')));
        $this->assertSame([], $this->reader()->parse('<html><body>não é feed</body></html>'));
    }

    public function test_an_external_entity_in_a_feed_is_never_expanded(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<rss version="2.0"><channel><item><title>&xxe;</title><link>https://feed.test/a</link>'
            .'<description>&xxe;</description></item></channel></rss>';

        $items = $this->reader()->parse($xml);

        foreach ($items as $item) {
            $this->assertStringNotContainsString('root:', (string) $item->title);
            $this->assertStringNotContainsString('root:', (string) $item->description);
        }
    }

    public function test_tracking_parameters_are_removed_but_port_and_other_parameters_stay(): void
    {
        $items = $this->reader()->parse($this->rss(
            '<item><title>T</title><link>https://feed.test:8443/p/a?page=2&amp;UTM_CAMPAIGN=x&amp;ref=home&amp;gclid=1</link></item>'
        ));

        $this->assertSame('https://feed.test:8443/p/a?page=2', $items[0]->url);
    }

    private function index(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/feeds/blog-index.json'));
    }

    public function test_a_json_index_is_read_with_only_the_section_after_the_hash_and_never_sends_the_hash(): void
    {
        Http::fake(['blog.test/blog-index.json' => Http::response($this->index(), 200, ['Content-Type' => 'application/json'])]);

        $items = $this->reader()->fetch('https://blog.test/blog-index.json#/blog/threat-intelligence/', 10);

        // Os mais novos primeiro, sem data no fim; fora da seção, sem título ou
        // com endereço que não é do próprio site, fica de fora.
        $this->assertSame([
            'https://blog.test/blog/threat-intelligence/2026/10/the-ultra-trail-from-viewer-to-mailer',
            'https://blog.test/blog/threat-intelligence/2026/08/dropcatch-scavengers',
            'https://blog.test/blog/threat-intelligence/sem-data',
        ], array_map(fn ($i) => $i->url, $items));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://blog.test/blog-index.json');
    }

    public function test_json_index_items_carry_title_date_summary_and_category(): void
    {
        [$ultra, $dropcatch, $undated] = $this->reader()->parseIndex($this->index(), 'https://blog.test/blog-index.json', '/blog/threat-intelligence/');

        $this->assertSame('The Ultra Trail: From Viewer to Mailer', $ultra->title);
        $this->assertSame('2026-10-29 05:59:00', $ultra->publishedAt->format('Y-m-d H:i:s'), 'em milissegundos');
        $this->assertSame('2026-08-13 05:58:00', $dropcatch->publishedAt->format('Y-m-d H:i:s'), 'em segundos');
        $this->assertSame('Domínios maliciosos expirados voltam ao ar.', $dropcatch->description);
        $this->assertSame(['Infoblox Threat Intel'], $dropcatch->categories);
        $this->assertSame('/blog/threat-intelligence/2026/08/dropcatch-scavengers', $dropcatch->guid);
        $this->assertNull($undated->publishedAt);
        $this->assertNull($undated->description);
    }

    public function test_without_a_section_the_whole_index_comes_and_the_limit_holds(): void
    {
        $all = $this->reader()->parseIndex($this->index(), 'https://blog.test:8443/blog-index.json');

        $this->assertCount(5, $all);
        $this->assertSame('https://blog.test:8443/blog/threat-intelligence/2026/10/the-ultra-trail-from-viewer-to-mailer', $all[0]->url);
        $this->assertCount(1, $this->reader()->parseIndex($this->index(), 'https://blog.test/blog-index.json', '', 0));
    }

    public function test_a_broken_json_index_is_reported(): void
    {
        foreach (['{"data": ', '{"columns": []}', '{"data": "x"}'] as $json) {
            $this->assertSame('Índice JSON inválido', $this->messageOf(fn () => $this->reader()->parseIndex($json, 'https://blog.test/i.json')));
        }

        $this->assertSame([], $this->reader()->parseIndex('{"data": [1, null, {"title": "sem caminho"}]}', 'https://blog.test/i.json'));
    }

    private function messageOf(callable $run): string
    {
        try {
            $run();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('devia ter lançado');
    }
}
