<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnrichmentNormalizer;
use App\Services\Search\CaseIndex;
use App\Support\ReportLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O Elasticsearch não faz parte da suíte.
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    private function article(array $extra = []): Article
    {
        return Article::create($extra + [
            'url' => 'https://example.com/a',
            'url_hash' => hash('sha256', 'https://example.com/a'),
            'source_host' => 'example.com',
            'original_title' => 'Acme patches CVE-2026-1234',
            'content' => 'Acme fixed an actively exploited RCE.',
            'collected_at' => now(),
            'status' => 'pending',
        ]);
    }

    private function bilingualJson(): string
    {
        return json_encode([
            'title' => 'Acme RCE exploited in the wild',
            'summary' => "**Acme** fixed it.\n\n### Technical Details\nBug.",
            'title_pt' => 'RCE da Acme explorado ativamente',
            'summary_pt' => "A **Acme** corrigiu.\n\n### Detalhes Técnicos\nFalha.",
            'new_context' => null,
            'new_context_pt' => 'não deveria sobreviver',
            'criticity' => 'critical',
            'cves' => ['CVE-2026-1234'],
        ]);
    }

    public function test_normalizer_reads_the_pt_br_edition(): void
    {
        $result = EnrichmentNormalizer::fromJson($this->bilingualJson(), $this->article(), 'hermes');

        $this->assertSame('RCE da Acme explorado ativamente', $result->titlePt);
        $this->assertStringContainsString('### Detalhes Técnicos', $result->summaryPt);
        $this->assertNull($result->newContextPt, 'sem new_context em inglês não há tradução');
    }

    public function test_writer_stores_both_editions_and_rewrites_a_case_in_place(): void
    {
        $writer = app(CaseWriter::class);
        $article = $this->article();
        $result = EnrichmentNormalizer::fromJson($this->bilingualJson(), $article, 'hermes');

        $case = $writer->createCase($article, $result);
        $this->assertSame('RCE da Acme explorado ativamente', $case->title_pt);
        $this->assertSame($result->summaryPt, $article->fresh()->summary_with_context_pt);

        $uuid = $case->uuid;
        $again = $writer->createCase($article, $result, $case);

        $this->assertSame($uuid, $again->uuid);
        $this->assertSame(1, NewsCase::query()->count());
    }

    private function bilingualCase(): NewsCase
    {
        $case = NewsCase::create([
            'title' => 'Acme RCE exploited in the wild',
            'title_pt' => 'RCE da Acme explorado ativamente',
            'summary' => 'English summary.',
            'summary_pt' => 'Resumo em português.',
            'criticity' => 'critical',
            'fingerprint' => 'acme',
            'first_at' => now(),
            'latest_at' => now(),
        ]);

        $this->article([
            'case_id' => $case->id,
            'status' => 'enriched',
            'title' => 'Acme RCE exploited in the wild',
            'title_pt' => 'RCE da Acme explorado ativamente',
            'summary' => 'English summary.',
            'summary_pt' => 'Resumo em português.',
        ]);

        return $case;
    }

    public function test_page_follows_the_reader_choice_and_the_browser_language(): void
    {
        $case = $this->bilingualCase();

        $this->withCookie(ReportLanguage::COOKIE, 'pt')->get("/materia/{$case->uuid}")
            ->assertOk()->assertSee('RCE da Acme explorado ativamente')->assertSee('Resumo em português.');

        $this->withCookie(ReportLanguage::COOKIE, 'en')->get("/materia/{$case->uuid}")
            ->assertOk()->assertSee('Acme RCE exploited in the wild')->assertDontSee('Resumo em português.');
    }

    public function test_browser_language_is_the_default(): void
    {
        $case = $this->bilingualCase();

        $this->get("/materia/{$case->uuid}", ['Accept-Language' => 'pt-BR,pt;q=0.9'])
            ->assertSee('Resumo em português.');

        $this->get("/materia/{$case->uuid}", ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertSee('English summary.');
    }

    public function test_case_without_pt_br_falls_back_to_english_with_a_notice(): void
    {
        $case = $this->bilingualCase();
        $case->forceFill(['title_pt' => null, 'summary_pt' => null])->save();

        $this->withCookie(ReportLanguage::COOKIE, 'pt')->get("/materia/{$case->uuid}")
            ->assertSee('Acme RCE exploited in the wild')
            ->assertSee('ainda não tem relatório em português');
    }

    public function test_public_api_keeps_the_original_english_contract(): void
    {
        $case = $this->bilingualCase();

        $json = $this->withCookie(ReportLanguage::COOKIE, 'pt')
            ->getJson("/api/news/case/{$case->uuid}")
            ->assertOk()
            ->assertJsonPath('title', 'Acme RCE exploited in the wild')
            ->assertJsonPath('latest_summary', 'English summary.')
            ->json();

        $this->assertArrayNotHasKey('report_lang', $json);
        $this->assertArrayNotHasKey('title_pt', $json);
        $this->assertArrayNotHasKey('summary_pt', $json['timeline'][0]);
    }

    public function test_language_switch_remembers_the_choice(): void
    {
        $this->from('/dashboard')->get('/idioma/pt')
            ->assertRedirect('/dashboard')
            ->assertCookie(ReportLanguage::COOKIE, 'pt');

        $this->get('/idioma/fr')->assertNotFound();
    }
}
