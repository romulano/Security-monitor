<?php

namespace Tests\Feature;

use App\Models\CaseMerge;
use App\Models\NewsCase;
use App\Models\PageView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use SimpleXMLElement;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\TestCase;

/**
 * O /sitemap.xml (2026-11-01): as páginas fixas e as matérias publicadas, do
 * banco, com a data da última mudança; nada de busca, API, admin, área com
 * login ou link assinado. Sem sessão e fora de Admin → Acessos.
 */
class SitemapTest extends TestCase
{
    use AlertsTestHelpers;
    use RefreshDatabase;

    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    protected function setUp(): void
    {
        parent::setUp();

        // O endereço do mapa é o da aplicação, não o da requisição (que nos testes é http://localhost).
        config(['app.url' => 'https://example.com']);
        // Teste sem RefreshDatabase que abre uma página grava fora da transação e o registro fica (como no PageViewsTest).
        PageView::query()->delete();
    }

    private function xml(TestResponse $response): SimpleXMLElement
    {
        $xml = new SimpleXMLElement((string) $response->getContent());
        $xml->registerXPathNamespace('s', self::XMLNS);

        return $xml;
    }

    /** @return array<string, ?string> endereço => lastmod */
    private function urls(TestResponse $response): array
    {
        $urls = [];

        foreach ($this->xml($response)->xpath('//s:url') as $url) {
            $urls[(string) $url->loc] = isset($url->lastmod) ? (string) $url->lastmod : null;
        }

        return $urls;
    }

    private function story(string $uuid): string
    {
        return 'https://example.com/materia/'.$uuid;
    }

    public function test_the_sitemap_lists_the_public_pages_and_only_the_published_cases(): void
    {
        $visible = $this->newsCase('Nginx RCE exploited in the wild');
        $hidden = $this->newsCase('Pixel 10 review', 'low');
        $hidden->hide('off_topic_feed');
        $deleted = $this->newsCase('Duplicate story');
        $deletedUuid = $deleted->uuid;
        $deleted->delete();
        // Apagada pela fusão de matérias: o uuid dela só redireciona para a nova.
        $merged = CaseMerge::query()->create(['uuid' => (string) Str::uuid(), 'case_id' => $visible->id, 'title' => 'Older story', 'merged_at' => now()]);

        $response = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8')
            ->assertHeader('X-Robots-Tag', 'noindex');

        $this->assertSame(self::XMLNS, $this->xml($response)->getNamespaces()['']);
        $urls = array_keys($this->urls($response));

        $this->assertSame([
            'https://example.com/',
            'https://example.com/dashboard',
            'https://example.com/sobre',
            'https://example.com/termos',
            'https://example.com/privacidade',
            $this->story($visible->uuid),
        ], $urls);

        foreach ([$hidden->uuid, $deletedUuid, $merged->uuid] as $gone) {
            $this->assertNotContains($this->story($gone), $urls);
        }

        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://example.com/', $url);
            $this->assertDoesNotMatchRegularExpression('#[?&]q=|/api/|/admin|/alertas|/conta|/(a|r|g)/#', $url);
        }
    }

    public function test_each_case_carries_the_date_of_its_last_real_change(): void
    {
        $old = $this->newsCase('Ivanti zero-day', attributes: [
            'created_at' => Carbon::parse('2026-10-01 10:00:00'),
            'latest_at' => Carbon::parse('2026-10-02 12:00:00'),
        ]);
        // A fusão (consolidated_at) e a explicação da falha também contam; o updated_at, que a indexação renova, não.
        $merged = $this->newsCase('PHP2Shell', attributes: [
            'created_at' => Carbon::parse('2026-10-03 09:00:00'),
            'latest_at' => Carbon::parse('2026-10-03 09:00:00'),
            'consolidated_at' => Carbon::parse('2026-10-05 08:30:00'),
            'vulnerability_explained_at' => Carbon::parse('2026-10-04 07:00:00'),
        ]);
        NewsCase::query()->whereKey([$old->id, $merged->id])->update(['indexed_at' => now()]);

        $urls = $this->urls($this->get('/sitemap.xml')->assertOk());

        $this->assertSame('2026-10-02T12:00:00+00:00', $urls[$this->story($old->uuid)]);
        $this->assertSame('2026-10-05T08:30:00+00:00', $urls[$this->story($merged->uuid)]);
        // O feed e o painel mudam com a matéria mais nova; as páginas de texto não dizem data.
        $this->assertSame('2026-10-05T08:30:00+00:00', $urls['https://example.com/']);
        $this->assertSame('2026-10-05T08:30:00+00:00', $urls['https://example.com/dashboard']);
        $this->assertNull($urls['https://example.com/termos']);
    }

    public function test_the_robot_that_already_has_the_file_gets_a_304(): void
    {
        $this->newsCase('Nginx RCE exploited in the wild', attributes: ['latest_at' => Carbon::parse('2026-10-02 12:00:00'), 'created_at' => Carbon::parse('2026-10-02 12:00:00')]);

        $first = $this->get('/sitemap.xml')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertSame('Fri, 02 Oct 2026 12:00:00 GMT', $first->headers->get('Last-Modified'));

        $again = $this->get('/sitemap.xml', ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame('', (string) $again->getContent());
    }

    public function test_reading_the_sitemap_opens_no_session_and_is_not_a_page_view(): void
    {
        $this->newsCase('Nginx RCE exploited in the wild');

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->get('/sitemap.xml')
            ->assertOk()
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
        // A parte que não existe (o site cabe num arquivo) também não vira acesso.
        $this->get('/sitemap-1.xml')->assertNotFound();

        $this->assertSame(0, PageView::query()->count());
    }

    public function test_a_large_sitemap_becomes_an_index_of_parts(): void
    {
        config(['secmon.sitemap.per_file' => 2]);
        $cases = [
            $this->newsCase('First', attributes: ['created_at' => Carbon::parse('2026-10-01 10:00:00'), 'latest_at' => Carbon::parse('2026-10-01 10:00:00')]),
            $this->newsCase('Second', attributes: ['created_at' => Carbon::parse('2026-10-02 10:00:00'), 'latest_at' => Carbon::parse('2026-10-02 10:00:00')]),
            $this->newsCase('Third', attributes: ['created_at' => Carbon::parse('2026-10-03 10:00:00'), 'latest_at' => Carbon::parse('2026-10-03 10:00:00')]),
        ];

        $index = $this->xml($this->get('/sitemap.xml')->assertOk());
        $this->assertSame('sitemapindex', $index->getName());
        $parts = [];

        foreach ($index->xpath('//s:sitemap') as $sitemap) {
            $parts[(string) $sitemap->loc] = (string) $sitemap->lastmod;
        }

        // A 1ª parte tem o feed, que muda com a matéria mais nova (a da 2ª parte).
        $this->assertSame([
            'https://example.com/sitemap-1.xml' => '2026-10-03T10:00:00+00:00',
            'https://example.com/sitemap-2.xml' => '2026-10-03T10:00:00+00:00',
        ], $parts);

        $first = array_keys($this->urls($this->get('/sitemap-1.xml')->assertOk()));
        $this->assertCount(7, $first);
        $this->assertSame('https://example.com/', $first[0]);
        $this->assertSame([$this->story($cases[0]->uuid), $this->story($cases[1]->uuid)], array_slice($first, 5));

        $this->assertSame([$this->story($cases[2]->uuid)], array_keys($this->urls($this->get('/sitemap-2.xml')->assertOk())));
        $this->get('/sitemap-3.xml')->assertNotFound();
        $this->get('/sitemap-0.xml')->assertNotFound();
    }
}
