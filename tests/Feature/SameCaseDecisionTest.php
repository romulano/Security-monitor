<?php

namespace Tests\Feature;

use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnricherManager;
use App\Services\Enrichment\EnrichmentNormalizer;
use App\Services\Enrichment\EnrichmentPrompt;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O agrupamento automático é só por título e CVE. Resumos semanais com o mesmo
 * molde ("… Week 39 (2026)", "… Week 40 (2026)") colidem; a IA, que lê os dois
 * textos, decide se é o mesmo assunto.
 */
class SameCaseDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O Elasticsearch não faz parte da suíte.
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    private function article(int $week, int $minutesAgo = 0): Article
    {
        $url = "https://www.sentinelone.com/blog/week-{$week}";

        return Article::create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'source_host' => 'sentinelone.com',
            'original_title' => "The Good, the Bad and the Ugly in Cybersecurity – Week {$week} (2026)",
            'content' => "Weekly roundup number {$week}.",
            'collected_at' => now()->subMinutes($minutesAgo),
            'status' => 'pending',
        ]);
    }

    private function analysis(string $title, bool $sameCase = true, ?string $newContext = null, ?array $caseReport = null): EnrichmentResult
    {
        return new EnrichmentResult(
            title: $title,
            summary: "Analysis: {$title}.",
            criticity: 'high',
            sameCase: $sameCase,
            newContext: $newContext,
            driver: 'hermes',
            caseTitle: $caseReport[0] ?? null,
            caseSummary: $caseReport[1] ?? null,
            caseTitlePt: $caseReport[2] ?? null,
            caseSummaryPt: $caseReport[3] ?? null,
        );
    }

    /** Caso aberto pela semana 39, como o crawler deixaria. */
    private function week39Case(): NewsCase
    {
        return app(CaseWriter::class)->createCase($this->article(39, 10), $this->analysis('Roundup week 39'));
    }

    public function test_ai_splits_a_wrongly_clustered_article_into_a_new_case(): void
    {
        $case = $this->week39Case();
        $week40 = $this->article(40);

        $this->mock(EnricherManager::class)
            ->shouldReceive('enrich')
            ->once()
            // O agrupamento por título junta a semana 40 ao caso da 39…
            ->withArgs(fn (Article $a, ?NewsCase $existing) => $existing?->is($case))
            // …e a IA recusa.
            ->andReturn([$this->analysis('Roundup week 40', sameCase: false), null]);

        EnrichArticle::dispatchSync($week40->id);

        $week40->refresh();
        $this->assertNotSame($case->id, $week40->case_id);
        $this->assertTrue($week40->is_first);
        $this->assertSame('Roundup week 40', $week40->newsCase->title);

        // O caso da semana 39 fica intacto.
        $case->refresh();
        $this->assertSame('Roundup week 39', $case->title);
        $this->assertSame(1, $case->update_count);
        $this->assertSame(1, $case->articles()->count());
        $this->assertSame(2, NewsCase::query()->count());
    }

    public function test_same_story_enriches_the_case_report_and_page_keeps_the_previous_analysis(): void
    {
        $case = $this->week39Case();
        $update = $this->article(40);

        $this->mock(EnricherManager::class)
            ->shouldReceive('enrich')
            ->once()
            ->andReturn([$this->analysis('Roundup week 39, follow-up', newContext: 'Patch released.', caseReport: [
                'Roundup week 39: patch released',
                "Analysis: Roundup week 39. The vendor released a patch (sentinelone.com).\n\n### Conflicting Reports\n- Two dates.",
                'Resumo da semana 39: patch lançado',
                "Análise da semana 39. O fornecedor lançou o patch (sentinelone.com).\n\n### Relatos divergentes\n- Duas datas.",
            ]), null]);

        EnrichArticle::dispatchSync($update->id);

        $case->refresh();
        $this->assertSame($case->id, $update->refresh()->case_id);
        $this->assertSame(2, $case->update_count);
        $this->assertSame(1, NewsCase::query()->count());

        // O caso mostra o relatório enriquecido; o artigo guarda a análise dele.
        $this->assertSame('Roundup week 39: patch released', $case->title);
        $this->assertStringContainsString('### Conflicting Reports', $case->summary);
        $this->assertStringContainsString('### Relatos divergentes', $case->summary_pt);
        $this->assertSame('Analysis: Roundup week 39, follow-up.', $update->summary);

        // A análise anterior continua na linha do tempo, com título e texto completos.
        $this->get(route('feed.show', $case->uuid))
            ->assertOk()
            ->assertSeeInOrder(['Roundup week 39: patch released', 'Relatos divergentes', 'Linha do tempo', 'Patch released.', 'Roundup week 39', 'primeiro registro'])
            ->assertSee('Analysis: Roundup week 39.');
    }

    public function test_an_update_without_the_enriched_report_does_not_shrink_the_case(): void
    {
        $case = $this->week39Case();
        $case->forceFill(['summary' => 'Long report built from several sources.', 'consolidated_at' => now()->subHour()])->save();

        // Ex.: a IA caiu e o heurístico analisou só o artigo.
        app(CaseWriter::class)->appendUpdate($case->refresh(), $this->article(40), $this->analysis('Short single-source analysis'));

        $case->refresh();
        $this->assertSame('Roundup week 39', $case->title);
        $this->assertSame('Long report built from several sources.', $case->summary);
        $this->assertSame(2, $case->update_count);
        // A fusão de matérias ainda vê a fonte que o relatório não leu.
        $this->assertTrue($case->consolidated_at->lt(now()->subMinutes(30)));
    }

    public function test_a_consolidated_case_stays_consolidated_with_the_new_source(): void
    {
        $case = $this->week39Case();
        $case->forceFill(['consolidated_at' => now()->subHour()])->save();

        app(CaseWriter::class)->appendUpdate($case->refresh(), $this->article(40), $this->analysis('Follow-up', caseReport: ['Story', 'Story enriched.', null, null]));

        $case->refresh();
        $this->assertSame('Story enriched.', $case->summary);
        $this->assertNull($case->summary_pt);
        $this->assertTrue($case->consolidated_at->gt(now()->subMinute()));
    }

    public function test_in_place_reanalysis_also_splits_other_stories(): void
    {
        $case = $this->week39Case();
        $week40 = $this->article(40);
        app(CaseWriter::class)->appendUpdate($case->refresh(), $week40, $this->analysis('Roundup week 40'));

        $this->mock(EnricherManager::class)
            ->shouldReceive('enrich')
            ->twice()
            ->andReturnUsing(fn (Article $a, ?NewsCase $existing) => $existing === null
                ? [$this->analysis('Roundup week 39'), null]
                : [$this->analysis('Roundup week 40', sameCase: false), null]);

        $this->artisan('secmon:reanalyze', ['--case' => [$case->uuid], '--force' => true])
            ->expectsOutputToContain('outro assunto, virou caso novo')
            ->assertSuccessful();

        $this->assertSame(2, NewsCase::query()->count());
        $this->assertSame(1, $case->refresh()->articles()->count());
        $this->assertNotSame($case->id, $week40->refresh()->case_id);
    }

    public function test_normalizer_reads_same_case_and_defaults_to_keeping_the_cluster(): void
    {
        $article = $this->article(39);
        $read = fn (array $data) => EnrichmentNormalizer::fromJson(json_encode($data + ['title' => 't', 'summary' => 's']), $article, 'hermes')->sameCase;

        $this->assertFalse($read(['same_case' => false]));
        $this->assertFalse($read(['same_case' => 'false']));
        $this->assertTrue($read(['same_case' => true]));
        $this->assertTrue($read([]));
        $this->assertTrue($read(['same_case' => 'talvez']));
    }

    public function test_prompt_asks_for_the_decision_only_when_clustered(): void
    {
        $this->assertContains('same_case', EnrichmentPrompt::schema()['required']);

        $case = $this->week39Case();
        $article = $this->article(40);

        $this->assertStringContainsString('same_case to false', EnrichmentPrompt::user($article, $case));
        $this->assertStringNotContainsString('<existing_case>', EnrichmentPrompt::user($article));
    }

    public function test_prompt_gives_the_whole_report_to_be_enriched_not_rewritten(): void
    {
        $case = $this->week39Case();
        $case->forceFill(['summary' => str_repeat('Detail from a source. ', 400).'LAST DETAIL'])->save();
        $prompt = EnrichmentPrompt::user($this->article(40), $case);

        // O relatório inteiro (antes ia cortado em 2.500 caracteres) é a base.
        $this->assertStringContainsString('known_report:', $prompt);
        $this->assertStringContainsString('LAST DETAIL', $prompt);
        $this->assertStringContainsString('never rewrite it from', EnrichmentPrompt::system());
        $this->assertStringContainsString('### Conflicting Reports', EnrichmentPrompt::system());

        foreach (['case_title', 'case_summary', 'case_title_pt', 'case_summary_pt'] as $field) {
            $this->assertContains($field, EnrichmentPrompt::schema()['required']);
        }
    }

    public function test_normalizer_reads_the_case_report_only_when_complete(): void
    {
        $article = $this->article(39);
        $read = fn (array $data) => EnrichmentNormalizer::fromJson(json_encode($data + ['title' => 't', 'summary' => 's']), $article, 'hermes');

        $full = $read(['case_title' => 'Case', 'case_summary' => 'Report.', 'case_title_pt' => 'Caso', 'case_summary_pt' => 'Relatório.']);
        $this->assertSame(['Case', 'Report.', 'Caso', 'Relatório.'], [$full->caseTitle, $full->caseSummary, $full->caseTitlePt, $full->caseSummaryPt]);

        // pt-BR pela metade cai inteiro; sem título, não há relatório.
        $half = $read(['case_title' => 'Case', 'case_summary' => 'Report.', 'case_title_pt' => 'Caso', 'case_summary_pt' => null]);
        $this->assertSame(['Case', 'Report.', null, null], [$half->caseTitle, $half->caseSummary, $half->caseTitlePt, $half->caseSummaryPt]);
        $this->assertNull($read(['case_title' => ' ', 'case_summary' => 'Report.'])->caseSummary);
        $this->assertNull($read([])->caseSummary);
    }
}
