<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Jobs\CollectAnalysisBatch;
use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\AnalysisBatch;
use App\Services\Enrichment\ClaudeEnricher;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Search\CaseIndex;
use App\Support\Settings;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * Reprocessamento em massa pelo lote da API: rodadas de um artigo por matéria,
 * a resposta entregue ao job do artigo, e o que não serve na análise normal.
 */
class AnalysisBatchTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> os lotes criados (corpo do POST) */
    private array $created = [];

    /** O estado e as linhas de resultado que a API falsa devolve. */
    private string $status = 'in_progress';

    /** @var list<array<string, mixed>> */
    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
        // Nenhuma chamada direta à API sai do teste: o endereço não responde.
        config([
            'secmon.enricher.claude.api_key' => 'chave-de-teste',
            'secmon.enricher.claude.base_url' => 'http://127.0.0.1:9',
            'secmon.enricher.claude.research' => false,
        ]);
        Settings::flush();
        Settings::setMany(['enricher.driver' => 'claude', 'enricher.claude_model' => 'claude-sonnet-5']);
        $this->app->instance(AnalysisBatch::class, (new AnalysisBatch)->withEnricher($this->claude()));
    }

    private function article(string $title, int $minutesAgo): Article
    {
        $url = 'https://news.test/'.md5($title);

        return Article::query()->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'source_host' => 'news.test',
            'original_title' => $title,
            'content' => "{$title}. Details of CVE-2026-1111 and the patch.",
            'collected_at' => now()->subMinutes($minutesAgo),
            'published_at' => now()->subMinutes($minutesAgo),
            'status' => 'pending',
        ]);
    }

    /** A matéria do PHP, aberta por um artigo já analisado. */
    private function story(): NewsCase
    {
        return app(CaseWriter::class)->createCase($this->article('PHP fixes critical CVE-2026-1111 flaw', 300)->refresh(), new EnrichmentResult(
            title: 'PHP fixes critical CVE-2026-1111 flaw', summary: 'PHP fixed the flaw.', criticity: 'high',
            indicators: [['field' => 'cves', 'label' => 'CVEs', 'values' => ['CVE-2026-1111']]], driver: 'claude',
        ));
    }

    /** Uma linha de resultado com a análise em JSON. */
    private function succeeded(Article $article, array $analysis, string $stop = 'end_turn'): array
    {
        return ['custom_id' => 'article-'.$article->id, 'result' => ['type' => 'succeeded', 'message' => [
            'id' => 'msg_'.$article->id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => json_encode($analysis)]],
            'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ]]];
    }

    public function test_one_article_per_story_per_round_and_the_hourly_requeue_leaves_them_alone(): void
    {
        Queue::fake();
        $story = $this->story();
        $first = $this->article('PHP CVE-2026-1111 flaw now exploited', 120);
        $second = $this->article('PHP CVE-2026-1111 flaw: patch now', 60);
        $other = $this->article('Retailer confirms breach of loyalty accounts', 90);

        $run = app(AnalysisBatch::class)->start([$first->id, $second->id, $other->id]);

        // Um por matéria: o segundo artigo do PHP espera o relatório que o primeiro escrever.
        $this->assertSame(['article-'.$first->id, 'article-'.$other->id], array_column($this->created[0]['requests'], 'custom_id'));
        $this->assertSame([$second->id], $run['pending']);
        $this->assertSame($story->id, $run['sent'][$first->id]['case']);
        $this->assertNull($run['sent'][$other->id]['case']);
        // O pedido é o da análise de sempre (modelo, teto, schema).
        $params = $this->created[0]['requests'][0]['params'];
        $this->assertSame('claude-sonnet-5', $params['model']);
        $this->assertSame(24000, $params['max_tokens']);
        $this->assertSame('json_schema', $params['output_config']['format']['type']);
        Queue::assertPushed(CollectAnalysisBatch::class);

        $this->artisan('secmon:enrich-pending')->assertSuccessful();
        Queue::assertNotPushed(EnrichArticle::class);
    }

    public function test_a_finished_round_hands_each_answer_to_its_job_without_another_call(): void
    {
        Queue::fake();
        $story = $this->story();
        $update = $this->article('PHP CVE-2026-1111 flaw now exploited', 120);
        $other = $this->article('Retailer confirms breach of loyalty accounts', 90);
        $batches = app(AnalysisBatch::class);
        $batches->start([$update->id, $other->id]);

        $this->assertSame('waiting', $batches->collect());

        $this->status = 'ended';
        $this->results = [
            $this->succeeded($update, ['title' => 'PHP flaw exploited', 'summary' => 'Attackers exploit it.', 'criticity' => 'critical',
                'same_case' => true, 'is_new_info' => true, 'case_title' => 'PHP flaw fixed and exploited', 'case_summary' => 'PHP fixed the flaw; attackers now exploit it.']),
            $this->succeeded($other, ['title' => 'Retailer breach', 'summary' => 'Loyalty accounts leaked.', 'criticity' => 'medium']),
        ];

        $this->assertSame('applying', $batches->collect());
        Queue::assertPushed(EnrichArticle::class, 2);

        // Os jobs gravam com a resposta do lote: a API não é chamada de novo (o endereço nem responde).
        foreach ([$update, $other] as $article) {
            app()->call([new EnrichArticle($article->id, 'refazer-materia'), 'handle']);
        }

        $this->assertSame('PHP flaw fixed and exploited', $story->refresh()->title);
        $this->assertSame('enriched', $update->refresh()->status);
        $this->assertSame('Retailer breach', $other->refresh()->newsCase->title);
        $this->assertSame('done', $batches->collect());
        $this->assertNull($batches->active());
    }

    public function test_an_answer_written_on_an_older_report_goes_to_the_next_round(): void
    {
        Queue::fake();
        $story = $this->story();
        $update = $this->article('PHP CVE-2026-1111 flaw now exploited', 120);
        $batches = app(AnalysisBatch::class);
        $batches->start([$update->id]);

        // Outra fonte atualizou a matéria enquanto o lote rodava.
        $story->forceFill(['summary' => 'PHP fixed the flaw. A second source adds the versions.'])->save();
        $this->status = 'ended';
        $this->results = [$this->succeeded($update, ['title' => 'Stale', 'summary' => 'Stale.', 'case_title' => 'Stale', 'case_summary' => 'Stale.'])];

        $this->assertSame('applying', $batches->collect());
        Queue::assertNotPushed(EnrichArticle::class);
        $this->assertNull(AnalysisBatch::take($update->id));

        // Nada gravando: a rodada seguinte leva o artigo, já com o relatório novo.
        $this->assertSame('submitted', $batches->collect());
        $this->assertCount(2, $this->created);
        $this->assertStringContainsString('A second source adds the versions.', json_encode($this->created[1]['requests'][0]['params']['messages']));
    }

    public function test_an_expired_or_cut_answer_goes_to_the_normal_analysis(): void
    {
        Queue::fake();
        $expired = $this->article('Retailer confirms breach of loyalty accounts', 90);
        $cut = $this->article('Ransomware hits a hospital network', 80);
        $batches = app(AnalysisBatch::class);
        $batches->start([$expired->id, $cut->id]);

        $this->status = 'ended';
        $this->results = [
            ['custom_id' => 'article-'.$expired->id, 'result' => ['type' => 'expired']],
            $this->succeeded($cut, ['title' => 'Cut'], 'max_tokens'),
        ];

        $this->assertSame('applying', $batches->collect());
        Queue::assertPushed(EnrichArticle::class, 2);
        $this->assertNull(AnalysisBatch::take($expired->id));
        $this->assertNull(AnalysisBatch::take($cut->id));
    }

    public function test_the_job_does_not_use_an_answer_written_on_another_report(): void
    {
        $story = $this->story();
        $update = $this->article('PHP CVE-2026-1111 flaw now exploited', 120);
        Cache::put('analysis-batch:article:'.$update->id, ['text' => '{"title": "Stale"}', 'case' => $story->id, 'stamp' => 'outro-relatorio'], now()->addHour());

        $this->mock(EnricherManager::class)->shouldReceive('enrich')->once()
            ->andReturn([new EnrichmentResult(title: 'Fresh analysis', summary: 'Fresh.', criticity: 'high', driver: 'claude'), null]);

        app()->call([new EnrichArticle($update->id, 'refazer-materia'), 'handle']);

        $this->assertSame('Fresh analysis', $update->refresh()->title);
    }

    public function test_the_command_only_batches_with_the_api_driver(): void
    {
        Settings::setMany(['enricher.driver' => 'hermes']);
        $this->artisan('secmon:reanalyze', ['--batch' => true, '--force' => true])
            ->expectsOutputToContain("só com o driver 'claude'")
            ->assertFailed();

        Settings::setMany(['enricher.driver' => 'claude']);
        $this->artisan('secmon:reanalyze', ['--batch' => true, '--sync' => true, '--force' => true])
            ->expectsOutputToContain('não roda com --sync')
            ->assertFailed();
    }

    /** O driver com uma API falsa de lotes: cria, informa o estado e devolve os resultados em JSONL. */
    private function claude(): ClaudeEnricher
    {
        $transporter = new class($this) implements ClientInterface
        {
            public function __construct(private AnalysisBatchTest $test) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->answer($request);
            }
        };

        return new ClaudeEnricher(new Client(
            apiKey: 'chave-de-teste',
            baseUrl: 'https://api.anthropic.test',
            requestOptions: ['transporter' => $transporter, 'maxRetries' => 0],
        ));
    }

    /** @internal a API falsa */
    public function answer(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/results')) {
            return new Response(200, ['Content-Type' => 'application/x-jsonl'], implode("\n", array_map('json_encode', $this->results)));
        }

        if ($request->getMethod() === 'POST') {
            $this->created[] = json_decode((string) $request->getBody(), true);
        }

        $id = 'msgbatch_'.count($this->created);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $id, 'type' => 'message_batch', 'archived_at' => null, 'cancel_initiated_at' => null,
            'created_at' => now()->toIso8601String(), 'ended_at' => $this->status === 'ended' ? now()->toIso8601String() : null,
            'expires_at' => now()->addDay()->toIso8601String(), 'processing_status' => $this->status,
            'request_counts' => ['processing' => 0, 'succeeded' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0],
            'results_url' => "https://api.anthropic.test/v1/messages/batches/{$id}/results",
        ]));
    }
}
