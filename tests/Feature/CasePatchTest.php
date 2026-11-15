<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\CasePatchFailed;
use App\Services\Enrichment\ClaudeEnricher;
use App\Services\Enrichment\EnrichmentNormalizer;
use App\Services\Enrichment\EnrichmentPrompt;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Enrichment\HermesEnricher;
use App\Services\Review\ReviewVerdict;
use App\Services\Search\CaseIndex;
use App\Support\Settings;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Atualização por trechos: na matéria que já existe, a análise devolve só as
 * partes do relatório que o artigo muda, e o normalizador as encaixa no
 * relatório atual. Daí para frente (revisor, CaseWriter) tudo vê o relatório inteiro.
 */
class CasePatchTest extends TestCase
{
    use RefreshDatabase;

    private const EN = "The **PHP** team fixed two flaws.\n\n### Technical Details\n- Out-of-memory corruption (php.net).\n\n### Remediation\n- Update to 8.4.27.";

    private const PT = "A equipe do **PHP** corrigiu duas falhas.\n\n### Detalhes Técnicos\n- Corrupção por falta de memória (php.net).\n\n### Remediação\n- Atualize para a 8.4.27.";

    protected function setUp(): void
    {
        parent::setUp();

        config(['secmon.enricher.case_patch' => true, 'secmon.enricher.hermes.research' => false]);
        Settings::flush();
        Settings::setMany(['enricher.hermes_url' => 'http://hermes.test:8765', 'enricher.hermes_model' => 'sonnet']);
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    private function story(array $attributes = []): NewsCase
    {
        return (new NewsCase)->forceFill($attributes + [
            'title' => 'PHP fixes two flaws',
            'title_pt' => 'PHP corrige duas falhas',
            'summary' => self::EN,
            'summary_pt' => self::PT,
            'indicators' => [],
        ]);
    }

    private function article(): Article
    {
        return new Article([
            'url' => 'https://www.bleepingcomputer.com/php',
            'source_host' => 'bleepingcomputer.com',
            'original_title' => 'PHP flaws exploited',
            'content' => 'Attackers now exploit the PHP flaws.',
        ]);
    }

    /** A resposta da análise com o relatório por trechos. */
    private function patched(mixed $patch, array $extra = []): string
    {
        return json_encode($extra + [
            'title' => 'PHP flaws exploited',
            'summary' => 'Attackers exploit the flaws.',
            'title_pt' => 'Falhas do PHP exploradas',
            'summary_pt' => 'Atacantes exploram as falhas.',
            'same_case' => true,
            'is_new_info' => true,
            'case_title' => 'PHP flaws fixed and exploited',
            'case_title_pt' => 'Falhas do PHP corrigidas e exploradas',
            'case_patch' => $patch,
        ]);
    }

    private function hermes(string ...$contents): void
    {
        Http::fake(['hermes.test:8765/*' => Http::sequence(array_map(
            fn (string $content) => Http::response(['choices' => [['message' => ['content' => $content]]]]),
            $contents,
        ))]);
    }

    public function test_the_schema_asks_for_the_changed_sections_instead_of_the_whole_report(): void
    {
        $patch = EnrichmentPrompt::schema(true);

        $this->assertArrayHasKey('case_patch', $patch['properties']);
        $this->assertArrayNotHasKey('case_summary', $patch['properties']);
        $this->assertArrayNotHasKey('case_summary_pt', $patch['properties']);
        $this->assertSame(array_keys($patch['properties']), $patch['required']);
        $this->assertSame(['body', 'technical', 'fix', 'remediation', 'conflicting'], $patch['properties']['case_patch']['required']);
        $this->assertSame(['en', 'pt'], $patch['properties']['case_patch']['properties']['fix']['required']);
        // No mesmo lugar do case_summary: a ordem dos campos é a ordem em que o modelo escreve.
        $keys = array_keys($patch['properties']);
        $this->assertSame(array_search('case_title', $keys) + 1, array_search('case_patch', $keys));

        $whole = EnrichmentPrompt::schema();
        $this->assertArrayHasKey('case_summary_pt', $whole['properties']);
        $this->assertArrayNotHasKey('case_patch', $whole['properties']);
    }

    public function test_patch_mode_only_when_the_report_can_take_a_patch(): void
    {
        $this->assertTrue(EnrichmentPrompt::patchMode($this->story()));
        $this->assertFalse(EnrichmentPrompt::patchMode(null));
        $this->assertFalse(EnrichmentPrompt::patchMode($this->story(['summary_pt' => null])));
        $this->assertFalse(EnrichmentPrompt::patchMode($this->story(['title_pt' => null])));
        $this->assertFalse(EnrichmentPrompt::patchMode($this->story(['summary' => self::EN."\n\n### Timeline\n- 2026-10-20: leak."])));
        // Relatório maior que o que o prompt mostra: a parte cortada não poderia ser reescrita.
        $this->assertFalse(EnrichmentPrompt::patchMode($this->story(['summary' => self::EN.str_repeat(' x', 10000)])));

        config(['secmon.enricher.case_patch' => false]);
        $this->assertFalse(EnrichmentPrompt::patchMode($this->story()));
    }

    public function test_the_prompt_gives_the_portuguese_report_and_the_rules_only_in_patch_mode(): void
    {
        $this->assertStringContainsString("known_report_pt:\n".self::PT, EnrichmentPrompt::user($this->article(), $this->story(), true));
        $this->assertStringNotContainsString('known_report_pt', EnrichmentPrompt::user($this->article(), $this->story()));

        $this->assertStringContainsString('return `case_patch`', EnrichmentPrompt::system(true));
        $this->assertStringNotContainsString('case_patch', EnrichmentPrompt::system());
        $this->assertStringContainsString('"case_patch"', HermesEnricher::system(true));
        $this->assertStringNotContainsString('case_patch', HermesEnricher::system());
    }

    public function test_the_normalizer_fits_the_sections_into_the_whole_report(): void
    {
        $result = EnrichmentNormalizer::fromJson($this->patched([
            'body' => ['en' => 'The **PHP** team fixed two flaws, now exploited (bleepingcomputer.com).', 'pt' => 'A equipe do **PHP** corrigiu duas falhas, agora exploradas (bleepingcomputer.com).'],
            'technical' => null,
            'fix' => ['en' => '- PR #62297.', 'pt' => '- PR #62297.'],
            'remediation' => null,
            'conflicting' => null,
        ]), $this->article(), 'hermes', $this->story());

        $this->assertSame('PHP flaws fixed and exploited', $result->caseTitle);
        $this->assertSame('Falhas do PHP corrigidas e exploradas', $result->caseTitlePt);
        $this->assertSame("The **PHP** team fixed two flaws, now exploited (bleepingcomputer.com).\n\n### Technical Details\n- Out-of-memory corruption (php.net).\n\n### Fix Details\n- PR #62297.\n\n### Remediation\n- Update to 8.4.27.", $result->caseSummary);
        $this->assertSame("A equipe do **PHP** corrigiu duas falhas, agora exploradas (bleepingcomputer.com).\n\n### Detalhes Técnicos\n- Corrupção por falta de memória (php.net).\n\n### Detalhes da correção\n- PR #62297.\n\n### Remediação\n- Atualize para a 8.4.27.", $result->caseSummaryPt);
    }

    public function test_no_patch_means_no_case_report_and_a_missing_title_keeps_the_story_title(): void
    {
        $none = EnrichmentNormalizer::fromJson($this->patched(null), $this->article(), 'hermes', $this->story());
        $this->assertNull($none->caseSummary);
        $this->assertNull($none->caseSummaryPt);

        $kept = EnrichmentNormalizer::fromJson($this->patched([], ['case_title' => null, 'case_title_pt' => '']), $this->article(), 'hermes', $this->story());
        $this->assertSame('PHP fixes two flaws', $kept->caseTitle);
        $this->assertSame('PHP corrige duas falhas', $kept->caseTitlePt);
        $this->assertSame(self::EN, $kept->caseSummary);
    }

    public function test_a_patch_that_does_not_fit_fails(): void
    {
        $fails = function (mixed $patch, ?NewsCase $story = null) {
            try {
                EnrichmentNormalizer::fromJson($this->patched($patch), $this->article(), 'hermes', $story ?? $this->story());
                $this->fail('o trecho devia ter sido recusado: '.json_encode($patch));
            } catch (CasePatchFailed) {
                $this->addToAssertionCount(1);
            }
        };

        $fails(['timeline' => ['en' => '- leak.', 'pt' => '- vazamento.']]);
        $fails(['fix' => ['en' => '- PR #62297.', 'pt' => '']]);
        $fails(['fix' => '- PR #62297.']);
        $fails(['body' => ['en' => '', 'pt' => '']]);
        $fails('sections');
        $fails(['fix' => ['en' => '- PR.', 'pt' => '- PR.']], $this->story(['summary' => self::EN."\n\n### Timeline\n- leak."]));

        $this->expectException(CasePatchFailed::class);
        EnrichmentNormalizer::fromJson($this->patched([]), $this->article(), 'hermes');
    }

    public function test_the_whole_report_format_still_works_with_the_switch_on(): void
    {
        $result = EnrichmentNormalizer::fromJson(json_encode([
            'title' => 't', 'summary' => 's', 'case_title' => 'Story', 'case_summary' => 'Whole report.',
            'case_title_pt' => 'História', 'case_summary_pt' => 'Relatório inteiro.',
        ]), $this->article(), 'hermes', $this->story());

        $this->assertSame('Whole report.', $result->caseSummary);
        $this->assertSame('Relatório inteiro.', $result->caseSummaryPt);
    }

    public function test_hermes_asks_for_the_sections_and_the_story_keeps_the_rest(): void
    {
        $this->hermes($this->patched(['body' => null, 'technical' => ['en' => "- Out-of-memory corruption (php.net).\n- Exploited since 4 November (bleepingcomputer.com).", 'pt' => "- Corrupção por falta de memória (php.net).\n- Explorada desde 4 de novembro (bleepingcomputer.com)."], 'fix' => null, 'remediation' => null, 'conflicting' => null]));

        $story = app(CaseWriter::class)->createCase($this->savedArticle('https://php.net/a'), new EnrichmentResult(
            title: 'PHP fixes two flaws', summary: self::EN, criticity: 'high', driver: 'hermes', titlePt: 'PHP corrige duas falhas', summaryPt: self::PT,
        ));
        $update = $this->savedArticle('https://www.bleepingcomputer.com/php');

        $result = (new HermesEnricher)->enrich($update, $story);
        app(CaseWriter::class)->appendUpdate($story, $update, $result);

        $story->refresh();
        $this->assertSame('PHP flaws fixed and exploited', $story->title);
        $this->assertStringContainsString("### Technical Details\n- Out-of-memory corruption (php.net).\n- Exploited since 4 November (bleepingcomputer.com).\n\n### Remediation\n- Update to 8.4.27.", $story->summary);
        $this->assertStringStartsWith('A equipe do **PHP** corrigiu duas falhas.', $story->summary_pt);
        $this->assertStringContainsString('- Explorada desde 4 de novembro (bleepingcomputer.com).', $story->summary_pt);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], '"case_patch"')
            && str_contains($r['messages'][1]['content'], 'known_report_pt:'));
    }

    public function test_hermes_redoes_the_analysis_with_the_whole_report_when_the_patch_does_not_fit(): void
    {
        Cache::flush();
        $this->hermes(
            $this->patched(['timeline' => ['en' => '- leak.', 'pt' => '- vazamento.']]),
            json_encode(['title' => 't', 'summary' => 's', 'case_title' => 'Story', 'case_summary' => 'Whole report.', 'case_title_pt' => 'História', 'case_summary_pt' => 'Relatório inteiro.']),
        );

        $result = (new HermesEnricher)->enrich($this->article(), $this->story());

        $this->assertSame('Whole report.', $result->caseSummary);
        $this->assertSame(1, Cache::get('enrichment:patch_fallback:'.now()->format('Y-m-d')));

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('known_report_pt:', $sent[0]['messages'][1]['content']);
        $this->assertStringNotContainsString('known_report_pt:', $sent[1]['messages'][1]['content']);
        $this->assertStringNotContainsString('case_patch', $sent[1]['messages'][0]['content']);
    }

    public function test_the_reviewer_redo_always_asks_for_the_whole_report(): void
    {
        $this->hermes(json_encode(['title' => 't', 'summary' => 's']));
        $draft = new EnrichmentResult(title: 't', summary: 's', criticity: 'high', driver: 'hermes', caseTitle: 'Story', caseSummary: self::EN);
        $verdict = new ReviewVerdict(ReviewVerdict::ADJUST, ReviewVerdict::ALL, [['field' => 'case_summary', 'excerpt' => '', 'instruction' => 'Fix it.']]);

        (new HermesEnricher)->revise($this->article(), $this->story(), $draft, 'update', $verdict);

        Http::assertSent(fn (Request $r) => ! str_contains($r['messages'][0]['content'], 'case_patch')
            && ! str_contains($r['messages'][1]['content'], 'known_report_pt'));
    }

    public function test_the_api_driver_fixes_the_patch_schema_in_the_output_format(): void
    {
        config(['secmon.enricher.claude.api_key' => 'chave-de-teste', 'secmon.enricher.claude.research' => false]);
        Settings::setMany(['enricher.claude_model' => 'claude-sonnet-5', 'enricher.claude_effort' => 'low']);
        $sent = [];

        $result = $this->claude([$this->patched(['body' => null, 'technical' => null, 'fix' => ['en' => '- PR #62297.', 'pt' => '- PR #62297.'], 'remediation' => null, 'conflicting' => null])], $sent)
            ->enrich($this->article(), $this->story());

        $this->assertStringContainsString("### Fix Details\n- PR #62297.", $result->caseSummary);
        $this->assertArrayHasKey('case_patch', $sent[0]['output_config']['format']['schema']['properties']);
        $this->assertStringContainsString('return `case_patch`', $sent[0]['system'][0]['text']);
        $this->assertStringContainsString('known_report_pt:', $sent[0]['messages'][0]['content']);
    }

    private function savedArticle(string $url): Article
    {
        return Article::create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'source_host' => parse_url($url, PHP_URL_HOST),
            'original_title' => 'PHP flaws',
            'content' => 'PHP fixed two flaws.',
            'collected_at' => now(),
            'status' => 'pending',
        ]);
    }

    /** O driver da API com um transporte falso que devolve os textos em ordem e guarda os pedidos. */
    private function claude(array $texts, array &$sent): ClaudeEnricher
    {
        $responses = array_map(fn (string $text) => new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ])), $texts);

        $transporter = new class($responses, $sent) implements ClientInterface
        {
            public function __construct(private array $responses, private array &$sent) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = json_decode((string) $request->getBody(), true);

                return array_shift($this->responses) ?? throw new RuntimeException('pedido a mais à API');
            }
        };

        return new ClaudeEnricher(new Client(
            apiKey: 'chave-de-teste',
            baseUrl: 'https://api.anthropic.test',
            requestOptions: ['transporter' => $transporter, 'maxRetries' => 0],
        ));
    }
}
