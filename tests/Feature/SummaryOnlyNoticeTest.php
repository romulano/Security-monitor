<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SummaryOnlyNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O Elasticsearch não faz parte da suíte.
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    private function newsCase(): NewsCase
    {
        return NewsCase::create([
            'title' => 'BeyondTrust zero-day exploited',
            'summary' => 'Short summary.',
            'criticity' => 'critical',
            'fingerprint' => 'beyondtrust',
            'first_at' => now()->subHour(),
            'latest_at' => now(),
        ]);
    }

    private function article(NewsCase $case, string $host, string $extraction, int $minutesAgo = 0): Article
    {
        $url = "https://{$host}/beyondtrust";

        return Article::create([
            'case_id' => $case->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'source_host' => $host,
            'original_title' => 'BeyondTrust zero-day exploited',
            'title' => 'BeyondTrust zero-day exploited',
            'summary' => 'Analysis.',
            'content' => 'Ivanti, Cisco, and Palo Alto Networks all had vulnerabilities this week.',
            'extraction' => $extraction,
            'collected_at' => now()->subMinutes($minutesAgo),
            'status' => 'enriched',
        ]);
    }

    public function test_case_built_only_from_feed_summaries_shows_the_notice(): void
    {
        $case = $this->newsCase();
        $this->article($case, 'www.darkreading.com', 'summary');

        $this->get("/materia/{$case->uuid}")
            ->assertOk()
            ->assertSee('Baseado só no resumo do feed.')
            ->assertSee('só resumo do feed')
            ->assertSee('https://www.darkreading.com/beyondtrust', false);
    }

    public function test_one_full_source_removes_the_notice_but_keeps_the_badge(): void
    {
        $case = $this->newsCase();
        $this->article($case, 'www.darkreading.com', 'summary');
        $this->article($case, 'www.bleepingcomputer.com', 'full', minutesAgo: 30);

        $this->get("/materia/{$case->uuid}")
            ->assertOk()
            ->assertDontSee('Baseado só no resumo do feed.')
            ->assertSee('só resumo do feed');
    }

    public function test_full_extraction_shows_nothing(): void
    {
        $case = $this->newsCase();
        $this->article($case, 'www.bleepingcomputer.com', 'full');

        $this->get("/materia/{$case->uuid}")
            ->assertOk()
            ->assertDontSee('resumo do feed');
    }

    public function test_api_contract_is_unchanged(): void
    {
        $case = $this->newsCase();
        $this->article($case, 'www.darkreading.com', 'summary');

        $json = $this->getJson("/api/news/case/{$case->uuid}")->assertOk()->json();

        $this->assertStringNotContainsString('summary_only', json_encode($json));
    }
}
