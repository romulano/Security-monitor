<?php

namespace Tests\Feature;

use App\Jobs\EnrichArticle;
use App\Jobs\MergeSimilarCases;
use App\Models\Article;
use App\Models\CrawlRun;
use App\Models\NewsCase;
use App\Models\Source;
use App\Services\Alerts\ProfileIndex;
use App\Services\Crawler\CaseRebuilder;
use App\Services\Crawler\CrawlerService;
use App\Services\Merge\CaseMerger;
use App\Services\Merge\MergeEditor;
use App\Services\Merge\SimilarCases;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\Feature\Search\FakeElasticsearch;
use Tests\TestCase;

/** Os comandos do operador (secmon:crawl, es-setup, reindex, reanalyze, cases-merge): o que imprimem, o que chamam e o que recusam. */
class ConsoleCommandsTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private function source(string $host = 'feed.test', bool $enabled = true): Source
    {
        $source = Source::create(['name' => ucfirst($host), 'host' => $host, 'feed_url' => "https://{$host}/rss"]);
        $source->forceFill(['enabled' => $enabled])->save();

        return $source;
    }

    private function crawlRun(Source $source, string $status = 'success'): CrawlRun
    {
        return CrawlRun::create([
            'source_id' => $source->id, 'trigger' => 'cli', 'status' => $status, 'started_at' => now(),
            'items_found' => 12, 'items_new' => 3, 'items_duplicate' => 8, 'items_failed' => 1,
        ]);
    }

    // ---- secmon:crawl -----------------------------------------------------

    public function test_crawl_refuses_a_source_that_does_not_exist(): void
    {
        $this->mock(CrawlerService::class)->shouldNotReceive('runSource');

        $this->artisan('secmon:crawl', ['--source' => 'nao-existe.test'])
            ->expectsOutput("Fonte 'nao-existe.test' não encontrada.")
            ->assertExitCode(1);
    }

    public function test_crawl_runs_one_source_found_by_host_or_by_id_and_prints_the_result(): void
    {
        $source = $this->source();
        $this->mock(CrawlerService::class, function (MockInterface $mock) use ($source) {
            $mock->shouldReceive('runSource')->twice()
                ->withArgs(fn (Source $s, string $trigger) => $s->is($source) && $trigger === 'cli')
                ->andReturn($this->crawlRun($source));
        });

        $this->artisan('secmon:crawl', ['--source' => 'feed.test'])->expectsOutputToContain('feed.test')->assertExitCode(0);
        $this->artisan('secmon:crawl', ['--source' => (string) $source->id])->assertExitCode(0);
    }

    public function test_crawl_all_skips_the_disabled_sources_and_ignores_the_interval(): void
    {
        $on = $this->source('ligada.test');
        $this->source('desligada.test', enabled: false);
        $this->mock(CrawlerService::class, function (MockInterface $mock) use ($on) {
            $mock->shouldReceive('runSource')->once()->withArgs(fn (Source $s) => $s->is($on))->andReturn($this->crawlRun($on));
            $mock->shouldNotReceive('runDue');
        });

        $this->artisan('secmon:crawl', ['--all' => true, '--trigger' => 'schedule'])->assertExitCode(0);
    }

    public function test_crawl_without_options_runs_only_what_is_due_and_says_when_nothing_is(): void
    {
        $this->mock(CrawlerService::class)->shouldReceive('runDue')->once()->with('cli')->andReturn([]);

        $this->artisan('secmon:crawl')
            ->expectsOutput('Nenhuma fonte no horário (ou crawler desligado no admin).')
            ->assertExitCode(0);
    }

    // ---- secmon:es-setup --------------------------------------------------

    public function test_es_setup_fails_when_the_search_service_is_unreachable(): void
    {
        $this->mock(CaseIndex::class)->shouldReceive('ping')->andReturn(false);
        $this->mock(ProfileIndex::class)->shouldNotReceive('create');

        $this->artisan('secmon:es-setup')->expectsOutputToContain('Elasticsearch inacessível em')->assertExitCode(1);
    }

    public function test_es_setup_creates_both_indexes_and_adds_the_fields_that_are_missing(): void
    {
        $this->mock(CaseIndex::class, function (MockInterface $mock) {
            $mock->shouldReceive('ping')->andReturn(true);
            $mock->shouldReceive('index')->andReturn('secmon_cases');
            $mock->shouldReceive('pruneHidden')->andReturn(0);
            $mock->shouldReceive('properties')->andReturn(['has_exploit' => ['type' => 'boolean']]);
            $mock->shouldReceive('create')->once();
            $mock->shouldReceive('drop')->never();
            $mock->shouldReceive('addMissingFields')->with('secmon_cases', \Mockery::type('array'))->andReturn(['has_exploit']);
            $mock->shouldReceive('addMissingFields')->with('secmon_profiles', \Mockery::type('array'))->andReturn([]);
        });
        $this->mock(ProfileIndex::class, function (MockInterface $mock) {
            $mock->shouldReceive('name')->andReturn('secmon_profiles');
            $mock->shouldReceive('create')->once();
            $mock->shouldReceive('drop')->never();
        });

        $this->artisan('secmon:es-setup')
            ->expectsOutputToContain('Campos novos em "secmon_cases": has_exploit')
            ->expectsOutputToContain('Índice "secmon_cases" pronto.')
            ->expectsOutputToContain('Índice de perfis "secmon_profiles" pronto.')
            ->assertExitCode(0);
    }

    public function test_es_setup_fresh_drops_both_indexes_first_because_the_percolator_copies_the_mapping(): void
    {
        $this->mock(CaseIndex::class, function (MockInterface $mock) {
            $mock->shouldReceive('ping')->andReturn(true);
            $mock->shouldReceive('index')->andReturn('secmon_cases');
            $mock->shouldReceive('pruneHidden')->andReturn(0);
            $mock->shouldReceive('properties')->andReturn([]);
            $mock->shouldReceive('drop')->once();
            $mock->shouldReceive('create')->once();
            $mock->shouldReceive('addMissingFields')->andReturn([]);
        });
        $this->mock(ProfileIndex::class, function (MockInterface $mock) {
            $mock->shouldReceive('name')->andReturn('secmon_profiles');
            $mock->shouldReceive('drop')->once();
            $mock->shouldReceive('create')->once();
        });

        $this->artisan('secmon:es-setup', ['--fresh' => true])
            ->expectsOutput('Índice anterior removido.')
            ->assertExitCode(0);
    }

    public function test_es_setup_warns_instead_of_failing_when_the_mapping_cannot_be_updated(): void
    {
        $this->mock(CaseIndex::class, function (MockInterface $mock) {
            $mock->shouldReceive('ping')->andReturn(true);
            $mock->shouldReceive('index')->andReturn('secmon_cases');
            $mock->shouldReceive('pruneHidden')->andReturn(0);
            $mock->shouldReceive('properties')->andReturn([]);
            $mock->shouldReceive('create');
            $mock->shouldReceive('addMissingFields')->andThrow(new \RuntimeException('mapping travado'));
        });
        $this->mock(ProfileIndex::class, function (MockInterface $mock) {
            $mock->shouldReceive('name')->andReturn('secmon_profiles');
            $mock->shouldReceive('create');
        });

        $this->artisan('secmon:es-setup')
            ->expectsOutputToContain('Não consegui atualizar o mapping de "secmon_cases": mapping travado')
            ->assertExitCode(0);
    }

    // ---- secmon:reindex ---------------------------------------------------

    public function test_reindex_fails_when_the_search_service_is_unreachable(): void
    {
        $es = FakeElasticsearch::answering([FakeElasticsearch::json([], 503)]);
        $this->app->instance(CaseIndex::class, $es->caseIndex());

        $this->artisan('secmon:reindex')->expectsOutput('Elasticsearch inacessível.')->assertExitCode(1);
    }

    public function test_reindex_sends_every_published_case_in_bulk_and_reports_the_count(): void
    {
        $a = $this->newsCase('Caso A');
        $b = $this->newsCase('Caso B');
        $hidden = $this->newsCase('Caso oculto');
        $hidden->forceFill(['is_published' => false])->save();

        $es = FakeElasticsearch::answering([
            FakeElasticsearch::json([]),                                   // ping
            FakeElasticsearch::json([]),                                   // o índice já existe
            FakeElasticsearch::json(['errors' => false, 'items' => []]),   // bulk
            FakeElasticsearch::json([]),                                   // refresh
            FakeElasticsearch::json(['count' => 2]),                       // count
        ]);
        $this->app->instance(CaseIndex::class, $es->caseIndex());

        $this->artisan('secmon:reindex')
            ->expectsOutputToContain('2 casos indexados. Documentos no índice: 2')
            ->assertExitCode(0);

        $lines = array_values(array_filter(explode("\n", (string) $es->requests()[2]->getBody())));
        $this->assertCount(4, $lines, 'dois casos publicados, o oculto fica de fora');
        $this->assertNotNull($a->fresh()->indexed_at);
        $this->assertNotNull($b->fresh()->indexed_at);
        $this->assertNull($hidden->fresh()->indexed_at);
    }

    public function test_reindex_fresh_recreates_the_index_before_sending(): void
    {
        $this->newsCase('Caso A');
        $es = FakeElasticsearch::answering([
            FakeElasticsearch::json([]),                                   // ping
            FakeElasticsearch::json([]),                                   // drop: existe?
            FakeElasticsearch::json(['acknowledged' => true]),             // drop
            FakeElasticsearch::json([], 404),                              // create: existe?
            FakeElasticsearch::json(['acknowledged' => true]),             // create
            FakeElasticsearch::json(['errors' => false, 'items' => []]),   // bulk
            FakeElasticsearch::json([]),                                   // refresh
            FakeElasticsearch::json(['count' => 1]),                       // count
        ]);
        $this->app->instance(CaseIndex::class, $es->caseIndex());

        $this->artisan('secmon:reindex', ['--fresh' => true])->assertExitCode(0);

        $this->assertSame('DELETE', $es->method(2));
        $this->assertSame('PUT', $es->method(4));
    }

    // ---- secmon:reanalyze -------------------------------------------------

    public function test_reanalyze_with_nothing_collected_says_so(): void
    {
        $this->artisan('secmon:reanalyze', ['--force' => true])
            ->expectsOutput('Nenhum artigo local para reanalisar.')
            ->assertExitCode(0);
    }

    public function test_reanalyze_releases_the_articles_drops_the_orphan_cases_and_queues_the_analysis(): void
    {
        Queue::fake();
        $case = $this->newsCase('Caso antigo');
        $article = Article::query()->where('case_id', $case->id)->firstOrFail();
        $article->forceFill(['attempts' => 2, 'error' => 'falhou', 'is_first' => true])->save();

        $this->mock(CaseIndex::class, function (MockInterface $mock) use ($case) {
            $mock->shouldReceive('forget')->once()->with($case->uuid);
            $mock->shouldReceive('refresh')->once();
        });

        $this->artisan('secmon:reanalyze', ['--force' => true])
            ->expectsOutputToContain('1 artigos enfileirados')
            ->assertExitCode(0);

        $article->refresh();
        $this->assertNull($article->case_id);
        $this->assertSame('pending', $article->status);
        $this->assertSame(0, $article->attempts);
        $this->assertNull($article->error);
        $this->assertFalse((bool) $article->is_first);
        $this->assertNull(NewsCase::query()->find($case->id), 'o caso ficou sem artigo e foi apagado');

        Queue::assertPushedOn('enrich', EnrichArticle::class, fn (EnrichArticle $job) => $job->articleId === $article->id);
    }

    public function test_reanalyze_can_be_limited_to_one_source_by_host(): void
    {
        Queue::fake();
        $mine = $this->newsCase('Da fonte A');
        $other = $this->newsCase('Da fonte B');
        Article::query()->where('case_id', $mine->id)->update(['source_host' => 'a.test']);
        Article::query()->where('case_id', $other->id)->update(['source_host' => 'b.test']);
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();

        $this->artisan('secmon:reanalyze', ['--force' => true, '--source' => 'a.test'])->assertExitCode(0);

        $this->assertNull(Article::query()->where('source_host', 'a.test')->value('case_id'));
        $this->assertNotNull(Article::query()->where('source_host', 'b.test')->value('case_id'));
        Queue::assertPushed(EnrichArticle::class, 1);
    }

    public function test_reanalyze_does_nothing_when_the_operator_declines_the_confirmation(): void
    {
        Queue::fake();
        $case = $this->newsCase('Caso');
        $this->mock(CaseIndex::class)->shouldNotReceive('forget');

        $this->artisan('secmon:reanalyze')->expectsConfirmation('Continuar?', 'no')->assertExitCode(0);

        $this->assertNotNull(Article::query()->where('case_id', $case->id)->value('id'));
        Queue::assertNothingPushed();
    }

    public function test_reanalyze_rebuilds_chosen_cases_in_place_and_reports_the_ones_that_do_not_exist(): void
    {
        $case = $this->newsCase('Caso para refazer');
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
        $rebuilder = $this->mock(CaseRebuilder::class);
        $rebuilder->shouldReceive('fullRebuild')->andReturn(true);
        $rebuilder->shouldReceive('rebuild')->once()->andReturn([
            ['article_id' => 77, 'driver' => 'hermes', 'bilingual' => true, 'split' => false, 'error' => null],
        ]);

        $this->artisan('secmon:reanalyze', ['--case' => [$case->uuid, 'nao-existe']])
            ->expectsOutputToContain('artigo #77 via hermes · EN + PT-BR')
            ->expectsOutput('Caso nao-existe não existe.')
            ->assertExitCode(0);
    }

    // ---- secmon:cases-merge -----------------------------------------------

    public function test_cases_merge_does_nothing_while_the_merge_is_switched_off(): void
    {
        Bus::fake();

        $this->artisan('secmon:cases-merge')
            ->expectsOutputToContain('Fusão desligada (CASES_MERGE)')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_cases_merge_asks_for_at_least_two_cases_when_choosing_by_uuid(): void
    {
        $one = $this->newsCase('Só uma');

        $this->artisan('secmon:cases-merge', ['--candidates' => true, '--case' => [$one->uuid]])
            ->expectsOutput('Informe ao menos duas matérias publicadas com --case=<uuid>.')
            ->assertExitCode(2);
    }

    public function test_cases_merge_candidates_lists_the_chosen_group_without_calling_the_ai(): void
    {
        Bus::fake();
        $a = $this->newsCase('Acme sofre ataque de ransomware');
        $b = $this->newsCase('Ransomware atinge a Acme');
        $this->mock(CaseMerger::class)->shouldReceive('outdated')->never();

        $this->artisan('secmon:cases-merge', ['--candidates' => true, '--case' => [$a->uuid, $b->uuid]])
            ->expectsOutputToContain('manual · 2 matérias')
            ->expectsOutputToContain('Acme sofre ataque de ransomware')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_cases_merge_queues_one_job_per_group_and_per_story_to_rewrite(): void
    {
        Bus::fake();
        $a = $this->newsCase('Matéria A');
        $b = $this->newsCase('Matéria B');
        $c = $this->newsCase('Matéria C');
        $this->mock(MergeEditor::class)->shouldReceive('enabled')->andReturn(true);
        $this->mock(SimilarCases::class)->shouldReceive('groups')->andReturn([['ids' => [$a->id, $b->id], 'score' => 0.91]]);
        $this->mock(CaseMerger::class)->shouldReceive('outdated')->andReturn([$c->id]);
        Cache::flush();

        $this->artisan('secmon:cases-merge')
            ->expectsOutputToContain('nota 0.91 · 2 matérias')
            ->expectsOutputToContain('1 grupo(s) e 1 matéria(s) consolidada(s) para atualizar na fila merge.')
            ->assertExitCode(0);

        Bus::assertDispatched(MergeSimilarCases::class, 2);
    }

    public function test_cases_merge_skips_a_group_the_ai_already_refused_until_it_changes(): void
    {
        Bus::fake();
        $a = $this->newsCase('Matéria A');
        $b = $this->newsCase('Matéria B');
        $this->mock(MergeEditor::class)->shouldReceive('enabled')->andReturn(true);
        $this->mock(SimilarCases::class)->shouldReceive('groups')->andReturn([['ids' => [$a->id, $b->id], 'score' => 0.8]]);
        $this->mock(CaseMerger::class)->shouldReceive('outdated')->andReturn([]);
        Cache::put(MergeSimilarCases::rejectedKey([$a->id, $b->id]), true, 3600);

        $this->artisan('secmon:cases-merge')
            ->expectsOutput('Nenhuma matéria parecida.')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_cases_merge_dry_run_shows_what_the_ai_would_join_and_writes_nothing(): void
    {
        Bus::fake();
        $a = $this->newsCase('Matéria A');
        $b = $this->newsCase('Matéria B');
        $this->mock(MergeEditor::class, function (MockInterface $mock) use ($a, $b) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('confirm')->once()->andReturn([['ids' => [$a->id, $b->id], 'reason' => 'mesmo incidente']]);
        });
        $this->mock(SimilarCases::class)->shouldReceive('groups')->andReturn([['ids' => [$a->id, $b->id], 'score' => 0.9]]);
        $this->mock(CaseMerger::class)->shouldReceive('outdated')->andReturn([]);

        $this->artisan('secmon:cases-merge', ['--dry-run' => true])
            ->expectsOutputToContain("juntar [{$a->id}, {$b->id}]: mesmo incidente")
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }
}
