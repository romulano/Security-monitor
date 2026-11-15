<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\NewsCase;
use App\Models\ReportReview;
use App\Models\User;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Enrichment\HermesEnricher;
use App\Services\Review\ReportDraft;
use App\Services\Review\ReportReviewer;
use App\Services\Review\ReviewOutcome;
use App\Services\Review\ReviewVerdict;
use App\Support\Prompts;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** O revisor do relatório: o parecer, o pedido de refação e o registro, com o hermes falso. */
class ReportReviewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::setMany(['enricher.hermes_url' => 'http://hermes.test', 'enricher.hermes_effort' => 'low']);
        Prompts::forget();
    }

    private function article(): Article
    {
        return Article::query()->create([
            'url' => 'https://example.com/acme',
            'url_hash' => hash('sha256', 'acme'),
            'source_host' => 'example.com',
            'original_title' => 'Acme patches CVE-2026-1234',
            'content' => 'Acme fixed CVE-2026-1234 in Gateway 4.2.1. It also mentions the 2019 flaw CVE-2019-0001 as background.',
            'status' => 'pending',
        ]);
    }

    private function draft(): EnrichmentResult
    {
        return new EnrichmentResult(
            title: 'Acme fixes CVE-2019-0001',
            summary: 'Acme fixed **CVE-2019-0001** in Gateway 4.2.1.',
            criticity: 'high',
            indicators: EnrichmentResult::indicatorGroups(['cves' => ['CVE-2019-0001'], 'vendors' => ['Acme']]),
            driver: 'hermes',
            titlePt: 'Acme corrige CVE-2019-0001',
            summaryPt: null,
        );
    }

    private static function reply(array $data): array
    {
        return ['choices' => [['message' => ['content' => "```json\n".json_encode($data)."\n```"]]]];
    }

    public function test_the_stage_is_born_off_and_the_admin_turns_it_on(): void
    {
        $this->assertFalse(app(ReportReviewer::class)->enabled());
        $this->assertSame(0, ReportReviewer::extraSeconds());

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.prompts.show', 'revisor'))
            ->assertOk()
            ->assertSee('Revisor do relatório')
            ->assertSee('Etapa desligada: é pulada e a IA não é chamada.')
            ->assertSee('Diretrizes do revisor')
            ->assertSee('Pedido de ajuste à análise');

        $this->actingAs($admin)->post(route('admin.prompts.toggle', 'revisor'))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Revisor do relatório: ligada.'));

        $this->assertTrue(app(ReportReviewer::class)->enabled());
        $this->assertSame(1200, ReportReviewer::extraSeconds());

        // As outras etapas continuam nascendo ligadas.
        $this->assertTrue(Prompts::stageOn('vulnerabilidade'));
    }

    public function test_the_reviewer_reads_the_source_and_the_draft_and_asks_for_part(): void
    {
        Http::fake(['hermes.test/*' => Http::response(self::reply([
            'verdict' => 'ajustar',
            'scope' => 'parte',
            'problems' => [
                ['field' => 'title', 'excerpt' => 'CVE-2019-0001', 'instruction' => 'O assunto é a CVE-2026-1234; a de 2019 é só contexto.'],
                ['field' => 'cves', 'excerpt' => 'CVE-2019-0001', 'instruction' => 'Trocar pela CVE-2026-1234.'],
            ],
            'note' => 'CVE histórica tratada como o assunto.',
        ]))]);

        $verdict = app(ReportReviewer::class)->review(new HermesEnricher, $this->article(), null, $this->draft(), 'new');

        $this->assertFalse($verdict->approved());
        $this->assertSame(ReviewVerdict::PART, $verdict->scope);
        $this->assertSame(['title', 'cves'], $verdict->fields());
        $this->assertSame('CVE histórica tratada como o assunto.', $verdict->note);

        Http::assertSent(function (Request $request) {
            $system = $request['messages'][0]['content'];
            $user = $request['messages'][1]['content'];

            return $request['reasoning_effort'] === 'low'
                && str_contains($system, 'copy editor of Security Monitor')
                && str_contains($system, '"verdict": "aprovado" or "ajustar"')
                && preg_match('/<source-([0-9a-f]{8}) kind="article">/', $user, $m)
                && str_contains($system, '<source-CODE>') && str_starts_with($user, "Code: {$m[1]}\n\n")
                && str_contains($user, 'Gateway 4.2.1. It also mentions the 2019 flaw')
                && str_contains($user, '"title": "Acme fixes CVE-2019-0001"')
                // O que falta é dito ao revisor: o resumo em pt-BR.
                && str_contains($user, 'Required fields missing or empty: summary_pt');
        });
    }

    public function test_the_update_shows_the_published_report_and_the_earlier_requests(): void
    {
        Http::fake(['hermes.test/*' => Http::response(self::reply(['verdict' => 'aprovado', 'scope' => null, 'problems' => [], 'note' => 'Fiel à fonte.']))]);
        $case = NewsCase::query()->create(['title' => 'Acme Gateway flaw', 'summary' => 'Published report about Acme.', 'first_at' => now(), 'latest_at' => now()]);
        $draft = $this->draft();
        $draft->caseTitle = 'Acme Gateway flaw patched';
        $draft->caseSummary = 'Report with the patch.';

        $earlier = [['verdict' => 'ajustar', 'scope' => 'parte', 'problems' => [['field' => 'case_title', 'excerpt' => '', 'instruction' => 'Citar a versão.']], 'note' => '']];
        $verdict = app(ReportReviewer::class)->review(new HermesEnricher, $this->article(), $case, $draft, 'update', $earlier);

        $this->assertTrue($verdict->approved());
        $this->assertSame([], $verdict->problems);

        Http::assertSent(fn (Request $request) => str_contains($request['messages'][1]['content'], 'kind="published_report"')
            && str_contains($request['messages'][1]['content'], 'Published report about Acme.')
            && str_contains($request['messages'][1]['content'], '- round 1, case_title: Citar a versão.')
            && str_contains($request['messages'][1]['content'], 'Required fields missing or empty: case_title_pt, case_summary_pt'));
    }

    public function test_invalid_answers_are_errors(): void
    {
        $fields = ReportDraft::names('new');

        foreach ([
            'sem JSON' => 'Looks fine to me.',
            'parecer fora da lista' => '{"verdict": "talvez"}',
            'sem escopo' => '{"verdict": "ajustar", "problems": [{"field": "title", "instruction": "x"}]}',
            'escopo fora da lista' => '{"verdict": "ajustar", "scope": "metade", "problems": [{"field": "title", "instruction": "x"}]}',
            'ajuste sem problema' => '{"verdict": "ajustar", "scope": "tudo", "problems": [{"field": "title", "instruction": ""}]}',
            'problemas que não são lista' => '{"verdict": "ajustar", "scope": "tudo", "problems": "o título"}',
        ] as $case => $content) {
            try {
                ReviewVerdict::parse($content, $fields);
                $this->fail("aceitou: {$case}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_part_without_a_known_field_becomes_all(): void
    {
        $verdict = ReviewVerdict::parse('{"verdict": "ajustar", "scope": "parte", "problems": [{"field": "headline", "excerpt": ["x"], "instruction": "Refazer."}]}', ReportDraft::names('new'));

        $this->assertSame(ReviewVerdict::ALL, $verdict->scope);
        $this->assertSame([['field' => null, 'excerpt' => '', 'instruction' => 'Refazer.']], $verdict->problems);
    }

    public function test_the_redo_request_and_the_partial_merge_keep_what_was_not_asked(): void
    {
        $draft = $this->draft();
        $verdict = new ReviewVerdict(ReviewVerdict::ADJUST, ReviewVerdict::PART, [
            ['field' => 'title', 'excerpt' => 'CVE-2019-0001', 'instruction' => 'Usar a CVE-2026-1234.'],
            ['field' => 'cves', 'excerpt' => '', 'instruction' => 'Só a CVE-2026-1234.'],
        ]);

        $request = ReportReviewer::redoRequest($draft, 'new', $verdict);
        $this->assertStringContainsString('fields_to_rewrite: title, cves', $request);
        $this->assertStringContainsString('- title ("CVE-2019-0001"): Usar a CVE-2026-1234.', $request);
        $this->assertStringContainsString('"summary": "Acme fixed **CVE-2019-0001** in Gateway 4.2.1."', $request);
        $this->assertStringContainsString('copy every other field from', $request);

        // A refação mexeu também no resumo e na criticidade; só o pedido vale.
        $redo = new EnrichmentResult(
            title: 'Acme fixes CVE-2026-1234',
            summary: 'Rewritten summary.',
            criticity: 'critical',
            indicators: EnrichmentResult::indicatorGroups(['cves' => ['CVE-2026-1234'], 'vendors' => ['Other']]),
            driver: 'hermes',
        );
        $merged = ReportDraft::apply($draft, $redo, $verdict->fields());

        $this->assertSame('Acme fixes CVE-2026-1234', $merged->title);
        $this->assertSame($draft->summary, $merged->summary);
        $this->assertSame('high', $merged->criticity);
        $this->assertSame(['CVE-2026-1234'], $merged->values('cves'));
        $this->assertSame(['Acme'], $merged->values('vendors'));
        $this->assertSame('Acme corrige CVE-2019-0001', $merged->titlePt);

        // Em `tudo`, vale a refação, mas o agrupamento é o da primeira análise e o vazio fica como estava.
        $redo->sameCase = false;
        $whole = ReportDraft::replace($draft, $redo);
        $this->assertTrue($whole->sameCase);
        $this->assertSame('Rewritten summary.', $whole->summary);
        $this->assertSame('Acme corrige CVE-2019-0001', $whole->titlePt);
    }

    public function test_the_outcome_is_recorded_without_the_report_text(): void
    {
        $case = NewsCase::query()->create(['title' => 'Acme', 'summary' => 'Report.', 'first_at' => now(), 'latest_at' => now()]);
        $article = $this->article();
        $rounds = [['verdict' => 'ajustar', 'scope' => 'parte', 'problems' => [['field' => 'title', 'excerpt' => 'x', 'instruction' => 'y']], 'note' => '']];

        $review = (new ReviewOutcome(ReportReview::PENDING, 'new', $rounds, adjustments: 2, calls: 5, seconds: 90))->record($case, $article);

        $this->assertSame(ReportReview::PENDING, $review->refresh()->status);
        // O jsonb reordena as chaves.
        $this->assertEquals($rounds, $review->rounds);
        $this->assertSame([['field' => 'title', 'excerpt' => 'x', 'instruction' => 'y']], $review->lastProblems());
        $this->assertSame([$review->id], ReportReview::query()->latestPerCase()->pluck('id')->all());

        ReviewOutcome::skipped('new', 'hermes fora')->record(null, $article);
        $this->assertSame(2, ReportReview::query()->count());

        // A fusão de matérias apaga a matéria: o registro vai junto.
        $case->delete();
        $this->assertSame(1, ReportReview::query()->count());
    }
}
