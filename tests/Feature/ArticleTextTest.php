<?php

namespace Tests\Feature;

use App\Jobs\EnrichArticle;
use App\Jobs\RebuildCase;
use App\Models\Article;
use App\Models\NewsCase;
use App\Models\User;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleTextTest extends TestCase
{
    use RefreshDatabase;

    private const FULL_TEXT = "BeyondTrust disclosed a zero-day in Remote Support (CVE-2026-9999).\n\n\n\nAttackers chained it with stolen API keys. ";

    protected function setUp(): void
    {
        parent::setUp();

        // O Elasticsearch não faz parte da suíte.
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function fullText(): string
    {
        return str_repeat(self::FULL_TEXT, 3);
    }

    private function article(string $extraction = 'summary', array $extra = []): Article
    {
        return Article::create($extra + [
            'url' => 'https://www.darkreading.com/beyondtrust',
            'url_hash' => hash('sha256', 'https://www.darkreading.com/beyondtrust'),
            'source_host' => 'darkreading.com',
            'original_title' => 'BeyondTrust zero-day exploited',
            'content' => 'Now BeyondTrust joins the group.',
            'content_chars' => 32,
            'extraction' => $extraction,
            'collected_at' => now(),
            'status' => 'pending',
        ]);
    }

    private function analysis(string $summary): EnrichmentResult
    {
        return new EnrichmentResult(
            title: 'BeyondTrust zero-day exploited',
            summary: $summary,
            criticity: 'critical',
            driver: 'hermes',
        );
    }

    private function caseFor(Article $article): NewsCase
    {
        return app(CaseWriter::class)->createCase($article, $this->analysis('Thin analysis.'));
    }

    public function test_filter_lists_feed_summaries_and_offers_the_paste_link(): void
    {
        $summary = $this->article('summary');
        $empty = $this->article('empty', ['url' => 'https://x.test/b', 'url_hash' => hash('sha256', 'b'), 'original_title' => 'Empty feed item']);
        $this->article('full', ['url' => 'https://x.test/c', 'url_hash' => hash('sha256', 'c'), 'original_title' => 'Full body item']);

        $this->actingAs($this->admin())
            ->get(route('admin.collections', ['extraction' => 'summary']))
            ->assertOk()
            ->assertSee('BeyondTrust zero-day exploited')
            ->assertSee('Empty feed item')
            ->assertDontSee('Full body item')
            ->assertSee(route('admin.articles.text', $summary), false)
            ->assertSee(route('admin.articles.text', $empty), false);
    }

    public function test_only_admin_can_paste(): void
    {
        $article = $this->article();

        $this->actingAs(User::factory()->create())->get(route('admin.articles.text', $article))->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->put(route('admin.articles.text.update', $article), ['content' => $this->fullText()])
            ->assertForbidden();

        $this->assertSame('summary', $article->fresh()->extraction);
    }

    public function test_paste_saves_the_text_and_queues_the_rebuild_of_the_case(): void
    {
        $article = $this->article();
        $case = $this->caseFor($article);
        Queue::fake();

        $this->actingAs($this->admin())
            ->get(route('admin.articles.text', $article))
            ->assertOk()
            ->assertSee('Now BeyondTrust joins the group.');

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['content' => "  \r\n".$this->fullText()])
            ->assertRedirect(route('admin.collections', ['extraction' => 'manual']))
            ->assertSessionHas('status');

        $article->refresh();
        $this->assertSame('manual', $article->extraction);
        $this->assertStringStartsWith('BeyondTrust disclosed', $article->content);
        $this->assertStringNotContainsString("\n\n\n", $article->content);
        $this->assertSame(mb_strlen($article->content), $article->content_chars);

        Queue::assertPushedOn('enrich', RebuildCase::class, fn (RebuildCase $job) => $job->caseId === $case->id && $job->articleId === $article->id);
        Queue::assertNotPushed(EnrichArticle::class);
    }

    public function test_article_without_case_goes_back_to_the_analysis_queue(): void
    {
        $article = $this->article('empty', ['status' => 'failed', 'attempts' => 2]);
        Queue::fake();

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['content' => $this->fullText()])
            ->assertRedirect();

        $this->assertSame('pending', $article->fresh()->status);
        $this->assertSame(0, $article->fresh()->attempts);
        Queue::assertPushedOn('enrich', EnrichArticle::class);
        Queue::assertNotPushed(RebuildCase::class);
    }

    public function test_short_text_is_refused(): void
    {
        $article = $this->article();

        $this->actingAs($this->admin())
            ->put(route('admin.articles.text.update', $article), ['content' => 'curto demais'])
            ->assertSessionHasErrors('content');

        $this->assertSame('summary', $article->fresh()->extraction);
    }

    public function test_rebuild_rewrites_the_case_in_place_and_drops_the_notice(): void
    {
        $article = $this->article();
        $case = $this->caseFor($article);
        $uuid = $case->uuid;

        $this->get(route('feed.show', $uuid))->assertSee('Baseado só no resumo do feed.');

        $article->forceFill(['content' => $this->fullText(), 'extraction' => 'manual'])->save();

        $this->mock(EnricherManager::class)
            ->shouldReceive('enrich')
            ->once()
            ->andReturn([$this->analysis('Rich analysis with the stolen API keys.'), null]);

        RebuildCase::dispatchSync($case->id, $article->id);

        $this->assertSame(1, NewsCase::query()->count());
        $this->assertSame($uuid, $case->fresh()->uuid);
        $this->assertSame('Rich analysis with the stolen API keys.', $case->fresh()->summary);

        $this->get(route('feed.show', $uuid))
            ->assertOk()
            ->assertSee('Rich analysis with the stolen API keys.')
            ->assertDontSee('resumo do feed');
    }
}
