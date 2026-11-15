<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\NewsCase;
use App\Models\PromptRevision;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnrichmentNormalizer;
use App\Services\Enrichment\HermesEnricher;
use App\Support\Prompts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `cves` traz só as falhas que são o assunto da matéria; as citadas como
 * contexto vão para `related_cves`, que não alimenta alertas, a busca
 * `cves:` nem o selo de exploit.
 */
class CveSubjectTest extends TestCase
{
    use RefreshDatabase;

    /** O caso real: a cadeia do Ivanti, a CVE do Windows posta no KEV no mesmo dia e as antigas do Ivanti. */
    private function ivantiArticle(): Article
    {
        return new Article([
            'url' => 'https://example.com/ivanti',
            'source_host' => 'example.com',
            'original_title' => 'Ivanti Connect Secure chain exploited in the wild',
            'content' => 'Attackers chain CVE-2026-104935 and CVE‑2026‑111357 in Ivanti Connect Secure. '
                .'The same day, CISA also added CVE-2026-96951, a Windows flaw, to KEV. '
                .'Ivanti appliances were hit before through CVE-2023-46805 and CVE-2024-21887.',
        ]);
    }

    private function normalize(array $json, ?Article $article = null)
    {
        return EnrichmentNormalizer::fromJson(json_encode($json + [
            'title' => 'Ivanti Connect Secure chain exploited',
            'summary' => 'Attackers chain two new flaws.',
            'criticity' => 'critical',
        ]), $article ?? $this->ivantiArticle(), 'hermes');
    }

    public function test_the_windows_cve_from_the_kev_list_and_the_old_ivanti_cves_stay_out_of_cves(): void
    {
        $result = $this->normalize([
            'cves' => ['CVE-2026-104935', 'cve-2026-111357'],
            'related_cves' => ['CVE-2026-96951', 'CVE-2023-46805', 'CVE-2024-21887'],
        ]);

        $this->assertSame(['CVE-2026-104935', 'CVE-2026-111357'], $result->values('cves'));
        $this->assertSame(['CVE-2026-96951', 'CVE-2023-46805', 'CVE-2024-21887'], $result->values('related_cves'));
    }

    public function test_a_cve_the_article_does_not_name_is_dropped_from_both_lists(): void
    {
        $result = $this->normalize([
            // CVE-2025-0282 e CVE-2025-22457 são do Ivanti, mas este artigo não as cita.
            'cves' => ['CVE-2026-104935', 'CVE-2025-0282'],
            'related_cves' => ['CVE-2025-22457', 'CVE-2024-21887', 'not a cve'],
        ]);

        $this->assertSame(['CVE-2026-104935'], $result->values('cves'));
        $this->assertSame(['CVE-2024-21887'], $result->values('related_cves'));
    }

    public function test_a_cve_in_both_lists_stays_only_in_cves(): void
    {
        $result = $this->normalize([
            'cves' => ['CVE-2026-104935', 'CVE-2024-21887'],
            'related_cves' => ['CVE-2024-21887', 'CVE-2026-96951'],
        ]);

        $this->assertSame(['CVE-2026-104935', 'CVE-2024-21887'], $result->values('cves'));
        $this->assertSame(['CVE-2026-96951'], $result->values('related_cves'));
    }

    public function test_the_old_json_without_related_cves_still_works(): void
    {
        $result = $this->normalize(['cves' => 'CVE-2026-104935, CVE-2026-96951']);

        $this->assertSame(['CVE-2026-104935', 'CVE-2026-96951'], $result->values('cves'));
        $this->assertSame([], $result->values('related_cves'));
        $this->assertNotContains('related_cves', array_column($result->indicators, 'field'));
    }

    public function test_the_case_keeps_the_old_indicators_and_a_cve_that_one_source_calls_main_leaves_the_context(): void
    {
        $writer = app(CaseWriter::class);

        // Indicadores gravados antes do campo novo: continuam lidos como sempre.
        $old = [['field' => 'cves', 'label' => 'CVEs', 'values' => ['CVE-2026-104935']]];
        $case = new NewsCase(['indicators' => $old]);
        $this->assertSame([], $case->indicatorValues('related_cves'));

        $merged = $writer->mergeIndicators($old, [
            ['field' => 'cves', 'label' => 'CVEs', 'values' => ['CVE-2026-111357']],
            ['field' => 'related_cves', 'label' => 'Related CVEs', 'values' => ['cve-2026-104935', 'CVE-2026-96951']],
        ]);

        $this->assertSame([
            ['field' => 'cves', 'label' => 'CVEs', 'values' => ['CVE-2026-104935', 'CVE-2026-111357']],
            ['field' => 'related_cves', 'label' => 'Related CVEs', 'values' => ['CVE-2026-96951']],
        ], $merged);

        // Só contexto que já é assunto: o grupo some, em vez de ficar vazio.
        $this->assertSame(
            [['field' => 'cves', 'label' => 'CVEs', 'values' => ['CVE-2026-104935']]],
            $writer->mergeIndicators($old, [['field' => 'related_cves', 'label' => 'Related CVEs', 'values' => ['CVE-2026-104935']]]),
        );
    }

    public function test_the_prompt_the_ai_receives_carries_the_rule_and_the_kev_example(): void
    {
        $system = HermesEnricher::system();

        $this->assertStringContainsString('`related_cves` lists the CVEs the article names only as context', $system);
        $this->assertStringContainsString('CVE-2026-96951', $system);
        $this->assertStringContainsString("A CVE neither names stays out,\n  even when you know it.", $system);
        $this->assertStringContainsString('"related_cves"', $system);
    }

    public function test_the_schema_keeps_the_rule_even_when_the_admin_edited_the_analysis_prompt(): void
    {
        PromptRevision::query()->create([
            'key' => 'enrichment.system',
            'body' => 'Old rules saved by the admin.',
            'base_hash' => sha1('versão anterior do padrão'),
        ]);
        Prompts::forget();

        $system = HermesEnricher::system();

        $this->assertStringStartsWith('Old rules saved by the admin.', $system);
        $this->assertStringNotContainsString('CVE-2026-96951', $system);
        $this->assertStringContainsString('Only the CVEs the article is about', $system);
        $this->assertStringContainsString('"related_cves"', $system);
    }
}
