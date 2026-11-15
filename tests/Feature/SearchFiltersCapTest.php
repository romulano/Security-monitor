<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Search\CaseIndex;
use App\Support\QueryTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Search\FakeElasticsearch;
use Tests\TestCase;

/**
 * O robô da busca de 2026-11-01 combinava 3 a 5 facetas aleatórias: até 4
 * filtros a busca consulta o Elasticsearch; acima, responde 422 sem consultar.
 * Os links de busca levam nofollow e a página com busca, noindex.
 */
class SearchFiltersCapTest extends TestCase
{
    use RefreshDatabase;

    private const FIVE = 'ttps_attack:T1190 criticity:critical victim_sectors:cryptocurrency victim_sectors:retail countries_code:SG';

    private const FOUR = 'ttps_attack:T1190 criticity:critical victim_sectors:cryptocurrency victim_sectors:retail';

    /** Um Elasticsearch de mentira com `$times` respostas iguais (o controller fica guardado na rota entre as requisições). */
    private function search(array $aggregations = [], int $times = 1): FakeElasticsearch
    {
        $es = FakeElasticsearch::answering(array_fill(0, $times, FakeElasticsearch::json([
            'hits' => ['total' => ['value' => 0], 'hits' => []],
            'aggregations' => $aggregations,
        ])));
        $this->app->instance(CaseIndex::class, $es->caseIndex());

        return $es;
    }

    public function test_more_than_four_filters_get_a_422_page_without_asking_the_search_engine(): void
    {
        $es = $this->search();

        $this->get(route('feed.index', ['q' => self::FIVE]))
            ->assertStatus(422)
            ->assertSee('Busca com filtros demais: use até 4 filtros (campo:valor) por vez.')
            // Os chips continuam ali para tirar um filtro, e a página fica fora do índice.
            ->assertSee('countries_code:SG')
            ->assertSee('<meta name="robots" content="noindex,follow">', false);

        $this->assertSame([], $es->requests());
    }

    public function test_the_api_answers_the_same_422_with_the_empty_envelope(): void
    {
        $es = $this->search();

        $this->getJson('/api/news?q='.urlencode(self::FIVE))
            ->assertStatus(422)
            ->assertJson(['cases' => [], 'total' => 0, 'error' => 'Busca com filtros demais: use até 4 filtros (campo:valor) por vez. Tire algum para ver os casos.']);

        $this->assertSame([], $es->requests());
    }

    public function test_four_filters_still_search(): void
    {
        $es = $this->search();

        $this->get(route('feed.index', ['q' => self::FOUR]))->assertOk();

        $this->assertSame('/secmon_cases/_search', $es->path());
    }

    public function test_a_list_in_q_is_an_empty_search_and_not_an_error(): void
    {
        $es = $this->search();

        $this->get('/?q[]=criticity:critical')->assertOk()->assertSee('<title>Security Monitor · feed de ameaças</title>', false);

        $this->assertSame([], $es->body()['query']['match_all']);
    }

    public function test_facet_links_stop_at_four_filters_and_search_links_are_nofollow(): void
    {
        $this->search(['threat_actors' => ['buckets' => [['key' => 'Lazarus', 'doc_count' => 3]]]], times: 2);

        // Com 3 filtros, a faceta leva ao 4º, com nofollow.
        $three = 'criticity:critical victim_sectors:retail ttps_attack:T1190';
        $this->get(route('feed.index', ['q' => $three]))
            ->assertOk()
            ->assertSee('href="'.e(route('feed.index', ['q' => QueryTokens::add($three, 'threat_actors', 'Lazarus')])).'" rel="nofollow"', false);

        // Com 4, a faceta que levaria ao 5º sai sem endereço; tirar um filtro continua com link.
        $this->get(route('feed.index', ['q' => self::FOUR]))
            ->assertOk()
            ->assertDontSee(e(route('feed.index', ['q' => QueryTokens::add(self::FOUR, 'threat_actors', 'Lazarus')])), false)
            ->assertSee('aria-disabled="true" title="Até 4 filtros por busca"', false)
            ->assertSee('href="'.e(route('feed.index', ['q' => QueryTokens::removeToken(self::FOUR, 'victim_sectors:retail')])).'" rel="nofollow"', false);
    }

    public function test_the_home_is_indexed_and_the_case_and_dashboard_filters_are_nofollow(): void
    {
        $this->search();
        $this->get(route('feed.index'))->assertOk()->assertDontSee('<meta name="robots"', false);

        $case = NewsCase::query()->create([
            'title' => 'Retailer breach', 'criticity' => 'high', 'summary' => 'Resumo.', 'victim_sectors' => ['retail'],
            'first_at' => now(), 'latest_at' => now(),
        ]);
        Article::query()->create([
            'case_id' => $case->id, 'url' => 'https://example.com/a', 'url_hash' => hash('sha256', 'a'),
            'source_host' => 'example.com', 'title' => 'Retailer breach', 'status' => 'enriched', 'collected_at' => now(),
        ]);

        $this->get(route('feed.show', $case->uuid))
            ->assertOk()
            ->assertSee('<a rel="nofollow" href="'.e(route('feed.index', ['q' => 'victim_sectors:retail'])).'"', false);

        $es = FakeElasticsearch::answering([FakeElasticsearch::json([
            'hits' => ['total' => ['value' => 1]],
            'aggregations' => ['top_victim_sectors' => ['buckets' => [['key' => 'retail', 'doc_count' => 1]]]],
        ])]);
        $this->app->instance(CaseIndex::class, $es->caseIndex());

        $this->get(route('feed.dashboard'))
            ->assertOk()
            ->assertSee('<a rel="nofollow" href="'.e(route('feed.index', ['q' => 'victim_sectors:retail'])).'"', false);
    }
}
