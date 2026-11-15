<?php

namespace Tests\Feature;

use App\Jobs\RebuildCase;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingEnricher;
use Tests\TestCase;

/**
 * Refazer a matéria grande (mais de `secmon.rebuild.full_max_articles` fontes):
 * só o artigo que pediu (e os que ficaram sem a IA) é relido contra a matéria
 * atual; a pequena continua refeita do zero.
 */
class RebuildLargeCaseTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = "hermes: hermes HTTP 502: You've hit your session limit";

    private RecordingEnricher $enricher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
        $this->enricher = new RecordingEnricher;
        $this->app->instance(EnricherManager::class, $this->enricher);
    }

    /** @return list<Article> a matéria com `$total` artigos, o primeiro abriu a matéria */
    private function caseWith(int $total): array
    {
        $articles = [];

        foreach (range(1, $total) as $i) {
            $article = Article::create([
                'url' => "https://fonte{$i}.test/php2shell",
                'url_hash' => hash('sha256', "php2shell-{$i}"),
                'source_host' => "fonte{$i}.test",
                'original_title' => "PHP2Shell {$i}",
                'content' => str_repeat('PHP2Shell is a critical RCE in PHP. ', 20),
                'content_chars' => 720,
                'extraction' => 'full',
                'collected_at' => now()->addMinutes($i),
                'status' => 'pending',
            ]);
            $result = new EnrichmentResult(title: 'PHP2Shell', summary: "Resumo {$i}.", criticity: 'critical', driver: 'hermes');

            $i === 1
                ? app(CaseWriter::class)->createCase($article, $result)
                : app(CaseWriter::class)->appendUpdate(NewsCase::query()->findOrFail($articles[0]->fresh()->case_id), $article, $result);

            $articles[] = $article->fresh();
        }

        return $articles;
    }

    /** Roda o job e os passos que ele pôs na fila, até o fim. */
    private function runAll(RebuildCase $job): int
    {
        $steps = 0;
        $queue = [$job];

        while ($queue) {
            Queue::fake();
            app()->call([array_shift($queue), 'handle']);
            $steps++;
            $queue = array_merge($queue, Queue::pushedJobs()[RebuildCase::class] ?? []);
            $queue = array_map(fn ($pushed) => is_array($pushed) ? $pushed['job'] : $pushed, $queue);
        }

        return $steps;
    }

    public function test_a_large_case_only_rereads_the_trigger_against_the_current_case(): void
    {
        $articles = $this->caseWith(9);
        $case = $articles[0]->newsCase;
        $trigger = $articles[4];

        Queue::fake();
        $this->assertTrue(RebuildCase::start($case->id, $trigger->id));

        $this->assertSame(1, $this->runAll(new RebuildCase($case->id, $trigger->id)));
        $this->assertSame([['article' => $trigger->id, 'with_case' => true]], $this->enricher->calls);
        $this->assertSame($case->uuid, $case->fresh()->uuid, 'o link continua o mesmo');
        $this->assertSame("Relatório com o artigo {$trigger->id}.", $trigger->fresh()->summary);
        $this->assertTrue($articles[0]->fresh()->is_first, 'a matéria não foi reescrita');
        $this->assertTrue(RebuildCase::start($case->id), 'o fim destrava a matéria');
    }

    public function test_the_first_article_of_a_large_case_stays_the_first(): void
    {
        $articles = $this->caseWith(9);

        $this->runAll(new RebuildCase($articles[0]->case_id, $articles[0]->id));

        $this->assertSame([['article' => $articles[0]->id, 'with_case' => true]], $this->enricher->calls);
        $this->assertTrue($articles[0]->fresh()->is_first);
        $this->assertSame($articles[0]->case_id, $articles[0]->fresh()->case_id);
    }

    public function test_the_mass_redo_of_a_large_case_only_rereads_the_articles_without_the_ai(): void
    {
        $articles = $this->caseWith(10);
        $articles[2]->forceFill(['error' => self::LIMIT])->save();
        $articles[7]->forceFill(['error' => 'hermes: Resposta do modelo não contém JSON.'])->save();
        $articles[5]->forceFill(['error' => 'HTTP 403'])->save();

        $this->assertSame(2, $this->runAll(new RebuildCase($articles[0]->case_id, $articles[2]->id)));

        $this->assertSame([$articles[2]->id, $articles[7]->id], $this->enricher->read());
        $this->assertSame([true, true], array_column($this->enricher->calls, 'with_case'));
        $this->assertNull($articles[2]->fresh()->error);
        $this->assertNull($articles[7]->fresh()->error);
        $this->assertSame('HTTP 403', $articles[5]->fresh()->error, 'erro de coleta não é da IA');
    }

    public function test_a_case_with_up_to_eight_articles_is_redone_from_scratch(): void
    {
        $articles = $this->caseWith(8);
        $case = $articles[0]->newsCase;
        Queue::fake();
        RebuildCase::start($case->id, $articles[4]->id);

        $this->assertSame(8, $this->runAll(new RebuildCase($case->id, $articles[4]->id)));

        $this->assertSame(array_map(fn (Article $a) => $a->id, $articles), $this->enricher->read());
        $this->assertFalse($this->enricher->calls[0]['with_case'], 'o primeiro reescreve a matéria');
        $this->assertTrue($this->enricher->calls[1]['with_case']);
        $this->assertTrue(RebuildCase::start($case->id));
    }

    public function test_the_limit_comes_from_the_config(): void
    {
        config(['secmon.rebuild.full_max_articles' => 2]);
        $articles = $this->caseWith(3);

        $this->runAll(new RebuildCase($articles[0]->case_id, $articles[1]->id));

        $this->assertSame([$articles[1]->id], $this->enricher->read());
    }

    /** O job já na fila antes da regra, com a lista cheia, segue refazendo do zero até o fim. */
    public function test_an_old_job_with_the_full_list_keeps_going(): void
    {
        $articles = $this->caseWith(9);
        $caseId = $articles[0]->case_id;
        $rest = array_map(fn (Article $a) => $a->id, array_slice($articles, 3));
        $old = 'O:20:"App\Jobs\RebuildCase":5:{s:6:"caseId";i:'.$caseId.';s:9:"articleId";i:'.$articles[0]->id
            .';s:9:"remaining";a:'.count($rest).':{'.implode('', array_map(fn ($id, $i) => "i:{$i};i:{$id};", $rest, array_keys($rest)))
            .'}s:4:"step";i:3;s:5:"queue";s:6:"enrich";}';
        Cache::add('rebuild-case:'.$caseId, true, 60);

        $job = unserialize($old);
        $this->assertFalse($job->incremental);
        $this->assertSame(6, $this->runAll($job));

        $this->assertSame($rest, $this->enricher->read());
        $this->assertSame([true, true, true, true, true, true], array_column($this->enricher->calls, 'with_case'));
        $this->assertTrue(RebuildCase::start($caseId), 'o último passo destrava a matéria');
    }

    public function test_the_full_redo_of_a_large_case_asks_with_the_estimate(): void
    {
        $articles = $this->caseWith(9);
        $uuid = $articles[0]->newsCase->uuid;

        $this->artisan('secmon:reanalyze', ['--case' => [$uuid]])
            ->expectsConfirmation("{$uuid}: 9 artigos, ~18 min de IA. Refazer do zero?", 'no')
            ->assertExitCode(0);
        $this->assertSame([], $this->enricher->calls);

        $this->artisan('secmon:reanalyze', ['--case' => [$uuid], '--force' => true])->assertExitCode(0);
        $this->assertCount(9, $this->enricher->calls);
        $this->assertFalse($this->enricher->calls[0]['with_case']);
    }
}
