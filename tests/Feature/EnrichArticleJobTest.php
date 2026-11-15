<?php

namespace Tests\Feature;

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseResolver;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnricherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrichArticleJobTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $status): Article
    {
        return Article::create([
            'url' => 'https://example.com/'.$status,
            'url_hash' => hash('sha256', 'https://example.com/'.$status),
            'source_host' => 'example.com',
            'original_title' => 'Acme patches CVE-2026-1234',
            'content' => 'Acme fixed an actively exploited RCE tracked as CVE-2026-1234.',
            'status' => $status,
        ]);
    }

    /**
     * O reprocessamento horário reenfileira o que está pendente, então o mesmo
     * artigo pode ter vários jobs na fila. Depois do primeiro, os outros não
     * podem gastar IA de novo.
     */
    public function test_already_resolved_articles_are_not_analyzed_again(): void
    {
        $this->mock(EnricherManager::class)->shouldNotReceive('enrich');

        foreach (['enriched', 'no_update'] as $status) {
            $article = $this->article($status);

            EnrichArticle::dispatchSync($article->id);

            $this->assertSame(0, $article->refresh()->attempts);
            $this->assertSame($status, $article->status);
        }
    }

    /**
     * Dois artigos do mesmo caso chegam juntos: o segundo espera o primeiro
     * enriquecer o relatório, senão a escrita de um apaga a do outro.
     */
    public function test_waits_while_another_article_enriches_the_same_case(): void
    {
        $case = NewsCase::query()->create(['title' => 'Acme patches CVE-2026-1234', 'summary' => 'Report.', 'cves' => ['CVE-2026-1234'], 'first_at' => now(), 'latest_at' => now()]);
        $article = $this->article('pending');

        $this->mock(CaseResolver::class)->shouldReceive('resolve')->andReturn($case);
        $this->mock(EnricherManager::class)->shouldNotReceive('enrich');

        $lock = NewsCase::reportLock($case->id, 60);
        $this->assertTrue($lock->get());

        $job = (new EnrichArticle($article->id))->withFakeQueueInteractions();
        $job->handle(app(EnricherManager::class), app(CaseResolver::class), app(CaseWriter::class));

        $job->assertReleased(60);
        $this->assertSame(0, $article->refresh()->attempts);
        $this->assertSame('pending', $article->status);

        $lock->release();
    }
}
