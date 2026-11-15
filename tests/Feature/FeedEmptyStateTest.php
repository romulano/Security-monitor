<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Search\FakeElasticsearch;
use Tests\TestCase;

/** O que o visitante lê quando o feed não tem nada para mostrar: nunca instrução de desenvolvedor. */
class FeedEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    private function searchReturns(array $hits, int $total): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json([
            'hits' => ['total' => ['value' => $total], 'hits' => $hits],
            'aggregations' => [],
        ])]);

        $this->app->instance(CaseIndex::class, $es->caseIndex());
    }

    public function test_a_search_without_results_says_so_and_offers_a_way_out(): void
    {
        $this->searchReturns([], 0);

        $this->get(route('feed.index', ['q' => 'zzzz']))
            ->assertOk()
            ->assertSee('Nenhum caso encontrado para essa busca.')
            ->assertSee('Tente outros termos ou tire algum filtro.')
            ->assertSee('Limpar a busca')
            ->assertDontSee('make crawl-all')
            ->assertDontSee('Rode ');
    }

    public function test_an_empty_feed_does_not_tell_the_visitor_to_run_a_command(): void
    {
        $this->searchReturns([], 0);

        $this->get(route('feed.index'))
            ->assertOk()
            ->assertSee('Ainda não há ameaças publicadas.')
            ->assertDontSee('make crawl-all')
            ->assertDontSee('Fontes &amp; bots', false);
    }

    public function test_an_empty_feed_points_the_admin_to_the_sources_page(): void
    {
        $this->searchReturns([], 0);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee('Fontes &amp; bots', false)
            ->assertSee(route('admin.sources'))
            ->assertDontSee('make crawl-all');
    }

    public function test_a_failing_search_shows_the_error_and_not_a_false_nothing_found(): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['error' => 'boom'], 503)]);
        $this->app->instance(CaseIndex::class, $es->caseIndex());

        $this->get(route('feed.index', ['q' => 'laravel']))
            ->assertOk()
            ->assertSee('Serviço de busca indisponível')
            ->assertDontSee('Nenhum caso encontrado')
            ->assertDontSee('Ainda não há ameaças');
    }

    public function test_a_page_past_the_end_sends_the_visitor_back_to_the_first_one(): void
    {
        $this->searchReturns([], 45);

        $this->get(route('feed.index', ['page' => 9]))
            ->assertOk()
            ->assertSee('Não há resultados nesta página.')
            ->assertSee('Voltar à primeira página')
            ->assertDontSee('Ainda não há ameaças');
    }

    public function test_a_missing_search_index_does_not_leak_the_operator_command_to_the_visitor(): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json(['error' => ['type' => 'index_not_found_exception']], 404)]);
        $this->app->instance(CaseIndex::class, $es->caseIndex());

        $this->get(route('feed.index'))
            ->assertOk()
            ->assertSee('A busca está sendo preparada.')
            ->assertDontSee('artisan')
            ->assertDontSee('es-setup');
    }
}
