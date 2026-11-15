<?php

namespace Tests\Feature;

use App\Jobs\RebuildCase;
use App\Models\Article;
use App\Models\NewsCase;
use App\Models\User;
use App\Services\Crawler\CaseRebuilder;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Admin → Coletas: as matérias que ficaram sem a IA (o hermes bateu no limite ou
 * respondeu sem JSON e entrou a heurística) aparecem com o motivo e voltam para a
 * fila com "Refazer com a IA"; o erro da IA sai quando ela responder.
 */
class RebuildWithoutAiTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = "hermes: hermes HTTP 502: You've hit your session limit · resets 12am (UTC)";

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function analysis(string $summary, string $driver = 'hermes'): EnrichmentResult
    {
        return new EnrichmentResult(title: 'Starlink volta a funcionar na Ucrânia', summary: $summary, criticity: 'medium', driver: $driver);
    }

    /** Um artigo que virou matéria, com o erro que ficou nele. */
    private function caseWith(string $slug, ?string $error, string $extraction = 'full'): Article
    {
        $article = Article::create([
            'url' => "https://techcrunch.com/{$slug}",
            'url_hash' => hash('sha256', $slug),
            'source_host' => 'techcrunch.com',
            'original_title' => "Matéria {$slug}",
            'content' => str_repeat('Starlink seems to be working normally again in Ukraine. ', 20),
            'content_chars' => 1100,
            'extraction' => $extraction,
            'collected_at' => now(),
            'status' => 'pending',
        ]);
        app(CaseWriter::class)->createCase($article, $this->analysis('Resumo da heurística.', 'heuristic'));
        $article->forceFill(['error' => $error])->save();

        return $article->fresh();
    }

    public function test_the_page_shows_how_many_were_left_without_the_ai_and_why(): void
    {
        $this->caseWith('limite-1', self::LIMIT);
        $this->caseWith('limite-2', self::LIMIT);
        $this->caseWith('json', 'hermes: Resposta do modelo não contém JSON.');
        $this->caseWith('waf', 'HTTP 403', 'summary');
        $this->caseWith('ok', null);

        $this->actingAs($this->admin())->get(route('admin.collections'))
            ->assertOk()
            ->assertSee('3 artigo(s) viraram matéria sem a análise da IA')
            ->assertSee('2×', false)
            ->assertSee("You've hit your session limit")
            ->assertSee('Resposta do modelo não contém JSON.')
            ->assertSee(route('admin.articles.rebuild-ai'), false);

        $this->actingAs($this->admin())->get(route('admin.collections', ['status' => 'sem_ia']))
            ->assertOk()
            ->assertSee('https://techcrunch.com/limite-1', false)
            ->assertSee('https://techcrunch.com/json', false)
            ->assertDontSee('https://techcrunch.com/waf', false)
            ->assertDontSee('https://techcrunch.com/ok', false);
    }

    public function test_nothing_to_redo_hides_the_button(): void
    {
        $this->caseWith('waf', 'HTTP 403', 'summary');

        $this->actingAs($this->admin())->get(route('admin.collections'))
            ->assertOk()
            ->assertDontSee(route('admin.articles.rebuild-ai'), false);

        $this->actingAs($this->admin())->from(route('admin.collections'))
            ->post(route('admin.articles.rebuild-ai'))
            ->assertRedirect(route('admin.collections'))
            ->assertSessionHas('error', 'Nenhuma matéria está sem a análise da IA.');
    }

    public function test_the_button_queues_one_rebuild_per_case(): void
    {
        Queue::fake();
        $first = $this->caseWith('limite-1', self::LIMIT);
        $second = $this->caseWith('limite-2', self::LIMIT);
        $this->caseWith('waf', 'HTTP 403', 'summary');

        // Uma atualização na matéria do primeiro, também sem IA: a matéria vai uma vez só.
        Article::create([
            'url' => 'https://bleepingcomputer.com/starlink', 'url_hash' => hash('sha256', 'bc'), 'source_host' => 'bleepingcomputer.com',
            'original_title' => 'Starlink update', 'content' => 'x', 'content_chars' => 1, 'extraction' => 'full',
            'collected_at' => now(), 'status' => 'enriched', 'case_id' => $first->case_id, 'error' => self::LIMIT,
        ]);

        $this->actingAs($this->admin())->from(route('admin.collections'))
            ->post(route('admin.articles.rebuild-ai'))
            ->assertRedirect(route('admin.collections'))
            ->assertSessionHas('status', '2 matéria(s) na fila para refazer com a IA. Elas são reescritas no lugar (o link continua o mesmo).');

        Queue::assertPushed(RebuildCase::class, 2);
        Queue::assertPushedOn('enrich', RebuildCase::class, fn (RebuildCase $job) => $job->caseId === $first->case_id && $job->articleId === $first->id);
        Queue::assertPushedOn('enrich', RebuildCase::class, fn (RebuildCase $job) => $job->caseId === $second->case_id);
    }

    public function test_the_rebuild_clears_the_ai_error_when_the_ai_answers(): void
    {
        $article = $this->caseWith('limite-1', self::LIMIT);
        $case = NewsCase::query()->findOrFail($article->case_id);

        $this->mock(EnricherManager::class)->shouldReceive('enrich')->once()
            ->andReturn([$this->analysis('Análise completa da IA.'), null]);

        app(CaseRebuilder::class)->rebuild($case);

        $this->assertNull($article->fresh()->error);
        $this->assertSame('Análise completa da IA.', $case->fresh()->summary);
        $this->assertSame($case->uuid, $case->fresh()->uuid, 'o link continua o mesmo');
    }

    public function test_the_rebuild_keeps_the_error_when_the_ai_fails_again(): void
    {
        $limited = $this->caseWith('limite-1', self::LIMIT);

        $this->mock(EnricherManager::class)->shouldReceive('enrich')->once()
            ->andReturn([$this->analysis('Heurística de novo.', 'heuristic'), self::LIMIT]);

        app(CaseRebuilder::class)->rebuild(NewsCase::query()->findOrFail($limited->case_id));

        $this->assertSame(self::LIMIT, $limited->fresh()->error, 'continua na lista para tentar de novo');
    }

    /** Matéria com várias fontes: um job por artigo, para o deploy não cortar tudo. */
    public function test_each_article_is_its_own_step(): void
    {
        $first = $this->caseWith('limite-1', self::LIMIT);
        $case = NewsCase::query()->findOrFail($first->case_id);
        $second = Article::create([
            'url' => 'https://bleepingcomputer.com/starlink',
            'url_hash' => hash('sha256', 'starlink-2'),
            'source_host' => 'bleepingcomputer.com',
            'original_title' => 'Starlink de volta',
            'content' => str_repeat('Starlink is back in Ukraine. ', 40),
            'content_chars' => 1160,
            'extraction' => 'full',
            'collected_at' => now()->addMinute(),
            'status' => 'pending',
        ]);
        app(CaseWriter::class)->appendUpdate($case, $second, $this->analysis('Atualização da heurística.', 'heuristic'));

        Queue::fake();
        $this->assertTrue(RebuildCase::start($case->id, $first->id));
        $this->assertFalse(RebuildCase::start($case->id, $first->id), 'a matéria já está sendo refeita');
        Queue::assertPushed(RebuildCase::class, 1);

        $this->mock(EnricherManager::class)->shouldReceive('enrich')->twice()
            ->andReturn([$this->analysis('Análise completa da IA.'), null]);

        app()->call([new RebuildCase($case->id, $first->id), 'handle']);

        Queue::assertPushed(RebuildCase::class, fn (RebuildCase $job) => $job->step === 1 && $job->remaining === [$second->id]);
        $this->assertSame('Análise completa da IA.', $case->fresh()->summary);
        $this->assertNull($first->fresh()->error);

        app()->call([new RebuildCase($case->id, $first->id, [$second->id], 1), 'handle']);

        Queue::assertPushed(RebuildCase::class, 2);
        $this->assertSame('enriched', $second->fresh()->status);
        $this->assertSame($case->id, $second->fresh()->case_id);
        $this->assertTrue(RebuildCase::start($case->id), 'o último passo destrava a matéria');
    }

    /** O job serializado antes dos passos (só caseId e articleId) começa do primeiro artigo. */
    public function test_a_job_queued_before_the_steps_still_runs(): void
    {
        $article = $this->caseWith('limite-1', self::LIMIT);
        $old = 'O:20:"App\Jobs\RebuildCase":3:{s:6:"caseId";i:'.$article->case_id.';s:9:"articleId";i:'.$article->id.';s:5:"queue";s:6:"enrich";}';

        $job = unserialize($old);
        $this->assertNull($job->remaining);
        $this->assertSame(0, $job->step);

        $this->mock(EnricherManager::class)->shouldReceive('enrich')->once()
            ->andReturn([$this->analysis('Análise completa da IA.'), null]);
        app()->call([$job, 'handle']);

        $this->assertNull($article->fresh()->error);
    }

    public function test_a_failed_step_unlocks_the_case(): void
    {
        $article = $this->caseWith('limite-1', self::LIMIT);
        Queue::fake();

        RebuildCase::start($article->case_id, $article->id);
        (new RebuildCase($article->case_id, $article->id))->failed();

        $this->assertTrue(RebuildCase::start($article->case_id, $article->id));
    }

    public function test_only_the_admin_redoes(): void
    {
        $this->caseWith('limite-1', self::LIMIT);

        $this->actingAs(User::factory()->create())->post(route('admin.articles.rebuild-ai'))->assertForbidden();
    }
}
