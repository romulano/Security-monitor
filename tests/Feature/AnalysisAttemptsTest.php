<?php

namespace Tests\Feature;

use App\Jobs\EnrichArticle;
use App\Jobs\RebuildCase;
use App\Models\Article;
use App\Models\ArticleAttempt;
use App\Models\User;
use App\Services\Admin\ArticleManager;
use App\Services\Crawler\AnalysisAttempts;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentNormalizer;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * O caso do artigo 2419 (02/11): o claude devolveu o JSON com uma quebra de linha
 * crua dentro do resumo e o artigo caiu na heurística; o "Refazer com a IA" deu
 * certo como repetição, mas o erro do claude ficou na tela. Agora o JSON é lido, o
 * erro velho sai e cada tentativa fica no histórico do artigo.
 */
class AnalysisAttemptsTest extends TestCase
{
    use RefreshDatabase;

    private const CTRL = 'claude: JSON inválido devolvido pelo modelo: Control character error, possibly incorrectly encoded';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    private function article(string $slug, array $extra = []): Article
    {
        return Article::create([
            'url' => "https://bleepingcomputer.com/{$slug}",
            'url_hash' => hash('sha256', $slug),
            'source_host' => 'bleepingcomputer.com',
            'original_title' => "Ivanti patches two more Connect Secure zero-days {$slug}",
            'content' => str_repeat('Ivanti warned customers about two more zero-days. ', 20),
            'content_chars' => 1000,
            'extraction' => 'full',
            'collected_at' => now()->subHours(3),
            'status' => 'pending',
        ] + $extra);
    }

    private function analysis(string $driver = 'hermes', bool $newInfo = true): EnrichmentResult
    {
        return new EnrichmentResult(title: 'Ivanti corrige mais dois zero-days', summary: 'Resumo.', criticity: 'high', isNewInfo: $newInfo, driver: $driver);
    }

    private function attempt(Article $article, string $trigger, string $result, ?string $error, string $at): void
    {
        ArticleAttempt::create(['article_id' => $article->id, 'trigger' => $trigger, 'driver' => 'hermes', 'result' => $result, 'error' => $error, 'created_at' => $at]);
    }

    public function test_raw_line_breaks_and_tabs_inside_strings_are_read(): void
    {
        $raw = "{\n  \"title\": \"Ivanti\",\n  \"summary\": \"Primeira linha.\nSegunda\tcom tab.\",\n  \"criticity\": \"high\"\n}";

        $result = EnrichmentNormalizer::fromJson($raw, $this->article('ctrl'), 'claude');

        $this->assertSame("Primeira linha.\nSegunda\tcom tab.", $result->summary);
        $this->assertSame('high', $result->criticity);
    }

    public function test_only_what_is_inside_strings_is_escaped(): void
    {
        $this->assertSame(
            "{\n\t\"a\": \"x\\ny\\u0001\\\"z\\r\",\r\n \"b\": 1}",
            EnrichmentNormalizer::escapeControlChars("{\n\t\"a\": \"x\ny\x01\\\"z\r\",\r\n \"b\": 1}"),
        );
    }

    public function test_other_json_errors_still_fail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON inválido devolvido pelo modelo');

        EnrichmentNormalizer::fromJson('{"title": "x", "summary": "y",}', $this->article('virgula'), 'claude');
    }

    /** O 2419: matéria grande, "Refazer com a IA" relê o artigo e ele sai como repetição. */
    public function test_the_redo_that_ends_as_a_repeat_clears_the_old_error(): void
    {
        $first = $this->article('primeiro');
        $case = app(CaseWriter::class)->createCase($first, $this->analysis());
        $repeat = $this->article('2419', ['collected_at' => now()]);
        app(CaseWriter::class)->appendUpdate($case, $repeat, $this->analysis('heuristic'));
        $repeat->forceFill(['error' => self::CTRL])->save();

        $this->mock(EnricherManager::class)->shouldReceive('enrich')->once()->andReturn([$this->analysis('hermes', false), null]);
        Queue::fake();

        app()->call([new RebuildCase($case->id, $repeat->id, [$repeat->id], 1, true, 'refazer-ia'), 'handle']);

        $repeat->refresh();
        $this->assertSame('no_update', $repeat->status);
        $this->assertNull($repeat->error, 'a repetição não guarda o erro da tentativa anterior');
        $this->assertSame(['refazer-ia', 'hermes', 'ok'], [$repeat->analysisAttempts->last()->trigger, $repeat->analysisAttempts->last()->driver, $repeat->analysisAttempts->last()->result]);
    }

    public function test_each_trigger_lands_in_the_history(): void
    {
        $article = $this->article('coleta');
        $this->mock(EnricherManager::class)->shouldReceive('enrich')
            ->andReturn([$this->analysis('heuristic'), self::CTRL], [$this->analysis(), null]);

        app()->call([new EnrichArticle($article->id), 'handle']);

        Queue::fake();
        app(ArticleManager::class)->retry($article->fresh());
        Queue::assertPushedOn('enrich', EnrichArticle::class, fn (EnrichArticle $job) => $job->trigger === 'retentar');
        app()->call([new EnrichArticle($article->id, 'retentar'), 'handle']);

        $attempts = $article->fresh()->analysisAttempts;
        $this->assertSame(['coleta', 'retentar'], $attempts->pluck('trigger')->all());
        $this->assertSame(['heuristica', 'ok'], $attempts->pluck('result')->all());
        $this->assertSame([self::CTRL, null], $attempts->pluck('error')->all());
        $this->assertNull($article->fresh()->error);
    }

    public function test_the_ai_button_marks_its_jobs_and_an_old_job_defaults_to_the_collection(): void
    {
        $article = $this->article('botao');
        app(CaseWriter::class)->createCase($article, $this->analysis('heuristic'));
        $article->forceFill(['error' => self::CTRL])->save();
        Queue::fake();

        app(ArticleManager::class)->rebuildWithoutAi();

        Queue::assertPushed(RebuildCase::class, fn (RebuildCase $job) => $job->trigger === 'refazer-ia');
        $old = unserialize('O:22:"App\Jobs\EnrichArticle":1:{s:9:"articleId";i:1;}');
        $this->assertSame('coleta', $old->trigger);
    }

    public function test_the_page_says_where_each_error_stands(): void
    {
        $never = $this->article('nunca', ['error' => self::CTRL]);
        $this->attempt($never, 'coleta', 'heuristica', self::CTRL, '2026-11-02 11:00:00');

        $again = $this->article('de-novo', ['error' => 'hermes: hermes HTTP 502: limite']);
        $this->attempt($again, 'coleta', 'heuristica', self::CTRL, '2026-11-02 11:00:00');
        $this->attempt($again, 'refazer-ia', 'heuristica', 'hermes: hermes HTTP 502: limite', '2026-11-02 12:10:00');
        $this->attempt($again, 'retentar', 'heuristica', 'hermes: hermes HTTP 502: limite', '2026-11-02 13:46:00');

        $fixed = $this->article('certo');
        $this->attempt($fixed, 'coleta', 'heuristica', self::CTRL, '2026-11-02 11:00:00');
        $this->attempt($fixed, 'refazer-ia', 'ok', null, '2026-11-02 14:19:00');

        $this->article('limpo');

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.collections'))
            ->assertOk()
            ->assertSee('ainda não foi pedido para refazer')
            ->assertSee('refeito em 02/11 10:46 e falhou de novo (2 vezes)')
            ->assertSee('refeito em 02/11 11:19 e deu certo')
            ->assertSee('refazer com a IA')
            ->assertSee('Control character error');
    }

    public function test_situations(): void
    {
        $attempts = app(AnalysisAttempts::class);
        $clean = $this->article('limpo');
        $this->assertNull($attempts->situation($clean));

        // A fusão passou, mas não refez a análise do artigo.
        $merged = $this->article('fusao', ['error' => self::CTRL]);
        $this->attempt($merged, 'coleta', 'heuristica', self::CTRL, '2026-11-02 11:00:00');
        $this->attempt($merged, 'fusao', 'ok', null, '2026-11-03 03:00:00');
        $this->assertSame('pending', $attempts->situation($merged->fresh())['state']);

        $once = $this->article('uma', ['error' => self::CTRL]);
        $this->attempt($once, 'refazer-materia', 'erro', self::CTRL, '2026-11-02 12:00:00');
        $this->assertSame(['failed_again', 1], array_values(array_intersect_key($attempts->situation($once->fresh()), ['state' => 0, 'failures' => 0])));
    }

    public function test_the_fusion_marks_only_articles_with_an_error(): void
    {
        $withError = $this->article('com-erro');
        $case = app(CaseWriter::class)->createCase($withError, $this->analysis('heuristic'));
        $withError->forceFill(['error' => self::CTRL])->save();
        $fine = $this->article('sem-erro', ['collected_at' => now()]);
        app(CaseWriter::class)->appendUpdate($case, $fine, $this->analysis());

        app(AnalysisAttempts::class)->recordFusion([$case->id], 'hermes: tempo esgotado');

        $this->assertSame(['fusao', 'erro'], [$withError->analysisAttempts()->first()->trigger, $withError->analysisAttempts()->first()->result]);
        $this->assertSame(0, $fine->analysisAttempts()->count());
    }

    public function test_old_attempts_are_pruned(): void
    {
        $article = $this->article('poda');
        $this->attempt($article, 'coleta', 'ok', null, now()->subDays(181)->toDateTimeString());
        $this->attempt($article, 'coleta', 'ok', null, now()->subDays(10)->toDateTimeString());

        $this->artisan('secmon:prune')->assertExitCode(0);

        $this->assertSame(1, ArticleAttempt::query()->count());
    }

    /** No deploy, o erro velho que a repetição deixou (o 2419 em prod) vai para o histórico. */
    public function test_the_migration_moves_stale_errors_to_the_history(): void
    {
        $stale = $this->article('2419', ['status' => 'no_update', 'enricher_driver' => 'hermes', 'error' => self::CTRL]);
        $real = $this->article('heuristica', ['status' => 'enriched', 'enricher_driver' => 'heuristic', 'error' => self::CTRL]);
        $media = $this->article('midia', ['status' => 'enriched', 'enricher_driver' => 'hermes', 'error' => 'Leitura dos prints/imagens falhou: x']);

        Schema::drop('article_attempts');
        (require database_path('migrations/2026_12_01_000100_create_article_attempts_table.php'))->up();

        $this->assertNull($stale->fresh()->error);
        $this->assertSame(['coleta', 'claude', 'heuristica', self::CTRL], array_values($stale->analysisAttempts()->first()->only(['trigger', 'driver', 'result', 'error'])));
        $this->assertSame(self::CTRL, $real->fresh()->error, 'sem a IA de verdade, o erro continua');
        $this->assertNotNull($media->fresh()->error);
        $this->assertSame(1, ArticleAttempt::query()->count());
    }
}
