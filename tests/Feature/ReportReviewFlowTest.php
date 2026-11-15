<?php

namespace Tests\Feature;

use App\Jobs\EnrichArticle;
use App\Jobs\RebuildCase;
use App\Models\Article;
use App\Models\NewsCase;
use App\Models\ReportReview;
use App\Services\Crawler\CaseResolver;
use App\Services\Search\CaseIndex;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O revisor no fluxo da análise: as rodadas, os ajustes em parte e em tudo, o
 * parecer final e as falhas, sempre com a matéria indo ao ar. Hermes falso.
 */
class ReportReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
        Settings::setMany([
            'enricher.enabled' => true,
            'enricher.driver' => 'hermes',
            'enricher.hermes_url' => 'http://hermes.test',
            'prompts.revisor' => true,
        ]);
    }

    private function article(): Article
    {
        return Article::query()->create([
            'url' => 'https://example.com/acme',
            'url_hash' => hash('sha256', 'acme'),
            'source_host' => 'example.com',
            'original_title' => 'Acme patches Gateway flaw',
            'content' => 'Acme fixed CVE-2026-1234 in Gateway 4.2.1. The 2019 flaw CVE-2019-0001 is mentioned as background.',
            'collected_at' => now(),
            'status' => 'pending',
        ]);
    }

    /** A resposta da análise (e da refação). */
    private static function analysis(string $title, string $summary = 'Acme fixed CVE-2026-1234 in Gateway 4.2.1.', array $extra = []): array
    {
        return self::chat([
            'title' => $title, 'summary' => $summary, 'criticity' => 'high', 'same_case' => true, 'is_new_info' => true,
            'title_pt' => $title.' (pt)', 'summary_pt' => $summary.' (pt)', 'cves' => ['CVE-2026-1234'],
        ] + $extra);
    }

    private static function approved(): array
    {
        return self::chat(['verdict' => 'aprovado', 'scope' => null, 'problems' => [], 'note' => 'Fiel à fonte.']);
    }

    private static function adjust(string $scope, ?string $field = 'title'): array
    {
        return self::chat(['verdict' => 'ajustar', 'scope' => $scope, 'problems' => [
            ['field' => $field, 'excerpt' => 'CVE-2019-0001', 'instruction' => 'O assunto é a CVE-2026-1234.'],
        ], 'note' => 'CVE histórica como assunto.']);
    }

    private static function chat(array $data): array
    {
        return ['choices' => [['message' => ['content' => json_encode($data)]]]];
    }

    private function fakeHermes(array ...$responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            $sequence->push(...$response);
        }

        Http::fake(['hermes.test/*' => $sequence]);
    }

    private function enrich(Article $article): NewsCase
    {
        EnrichArticle::dispatchSync($article->id);

        return $article->refresh()->newsCase;
    }

    public function test_approved_at_first_costs_one_call(): void
    {
        $this->fakeHermes([self::analysis('Acme fixes CVE-2026-1234')], [self::approved()]);

        $case = $this->enrich($article = $this->article());

        $this->assertSame('Acme fixes CVE-2026-1234', $case->title);
        $this->assertTrue($case->is_published);
        Http::assertSentCount(2);

        $review = ReportReview::query()->sole();
        $this->assertSame([ReportReview::APPROVED, 'new', 'coleta', 0, 1], [$review->status, $review->mode, $review->origin, $review->adjustments, $review->calls]);
        $this->assertSame([$case->id, $article->id], [$review->case_id, $review->article_id]);
        $this->assertSame('aprovado', $review->rounds[0]['verdict']);
    }

    public function test_adjust_part_rewrites_only_the_fields_asked(): void
    {
        $this->fakeHermes(
            [self::analysis('Acme fixes CVE-2019-0001')],
            [self::adjust('parte')],
            // A refação mexeu também no resumo: só o título vale.
            [self::analysis('Acme fixes CVE-2026-1234', 'Rewritten summary.')],
            [self::approved()],
        );

        $case = $this->enrich($this->article());

        $this->assertSame('Acme fixes CVE-2026-1234', $case->title);
        $this->assertSame('Acme fixed CVE-2026-1234 in Gateway 4.2.1.', $case->summary);
        $this->assertSame('Acme fixes CVE-2019-0001 (pt)', $case->title_pt);

        $review = ReportReview::query()->sole();
        $this->assertSame([ReportReview::APPROVED, 1, 3], [$review->status, $review->adjustments, $review->calls]);
        $this->assertSame(['ajustar', 'aprovado'], array_column($review->rounds, 'verdict'));

        // A refação é a mensagem da análise com o pedido do revisor no fim.
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'JSON Schema')
            && str_contains($request['messages'][1]['content'], "<review>\n")
            && str_contains($request['messages'][1]['content'], 'fields_to_rewrite: title')
            && str_contains($request['messages'][1]['content'], 'O assunto é a CVE-2026-1234.'));
    }

    public function test_adjust_all_takes_the_whole_new_version(): void
    {
        $this->fakeHermes(
            [self::analysis('Acme fixes CVE-2019-0001', 'Wrong story.')],
            [self::adjust('tudo', null)],
            [self::analysis('Acme fixes CVE-2026-1234', 'Right story.')],
            [self::approved()],
        );

        $case = $this->enrich($this->article());

        $this->assertSame('Acme fixes CVE-2026-1234', $case->title);
        $this->assertSame('Right story.', $case->summary);
        $this->assertSame('Right story. (pt)', $case->summary_pt);
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][1]['content'], 'scope: tudo')
            && ! str_contains($request['messages'][1]['content'], "\nfields_to_rewrite:"));
    }

    public function test_after_two_adjustments_the_final_verdict_publishes_with_review_pending(): void
    {
        $this->fakeHermes(
            [self::analysis('Version 1')],
            [self::adjust('parte')],
            [self::analysis('Version 2')],
            [self::adjust('parte')],
            [self::analysis('Version 3')],
            [self::adjust('parte')],
        );

        $case = $this->enrich($this->article());

        // Não trava a notícia: sai com a última versão.
        $this->assertSame('Version 3', $case->title);
        $this->assertTrue($case->is_published);
        Http::assertSentCount(6);

        $review = ReportReview::query()->sole();
        $this->assertSame([ReportReview::PENDING, 2, 5, 'reprovado no parecer final'], [$review->status, $review->adjustments, $review->calls, $review->reason]);
        $this->assertCount(3, $review->rounds);
        $this->assertSame('O assunto é a CVE-2026-1234.', $review->lastProblems()[0]['instruction']);
    }

    public function test_hermes_down_skips_the_review_and_records_it(): void
    {
        // O hermes cai depois da análise: a matéria sai como a análise escreveu.
        $this->fakeHermes(
            [self::analysis('Acme fixes CVE-2026-1234')],
            [['error' => ['message' => 'conexão recusada']], 502],
            [['error' => ['message' => 'fora']], 502],
        );

        $case = $this->enrich($this->article());

        $this->assertSame('Acme fixes CVE-2026-1234', $case->title);
        $review = ReportReview::query()->sole();
        $this->assertSame(ReportReview::SKIPPED, $review->status);
        $this->assertStringContainsString('o revisor falhou: hermes HTTP 502: conexão recusada', $review->reason);

        // O hermes fora desde a análise: ela cai na extração automática e o revisor nem é chamado.
        $other = Article::query()->create(['url' => 'https://example.com/b', 'url_hash' => hash('sha256', 'b'), 'source_host' => 'example.com', 'original_title' => 'Other story about Zeta', 'content' => 'Zeta was breached.', 'collected_at' => now(), 'status' => 'pending']);

        $this->assertNotNull($this->enrich($other));
        Http::assertSentCount(3);
        $this->assertStringContainsString('a análise saiu sem a IA (hermes: hermes HTTP 502: fora)', ReportReview::query()->latest('id')->first()->reason);
    }

    public function test_without_time_left_in_the_job_the_review_is_skipped(): void
    {
        config(['secmon.review.budget' => 10]);
        $this->fakeHermes([self::analysis('Acme fixes CVE-2026-1234')]);

        $this->enrich($this->article());

        Http::assertSentCount(1);
        $this->assertSame(['skipped', 'sem tempo no job para o parecer'], [ReportReview::query()->sole()->status, ReportReview::query()->sole()->reason]);
    }

    public function test_invalid_reviewer_answer_publishes_and_records_it(): void
    {
        $this->fakeHermes([self::analysis('Acme fixes CVE-2026-1234')], [self::chat(['verdict' => 'talvez'])]);

        $case = $this->enrich($this->article());

        $this->assertSame('Acme fixes CVE-2026-1234', $case->title);
        $this->assertSame(ReportReview::SKIPPED, ReportReview::query()->sole()->status);
        $this->assertStringContainsString('parecer fora da lista', ReportReview::query()->sole()->reason);
    }

    public function test_stage_off_keeps_todays_flow(): void
    {
        Settings::set('prompts.revisor', false);
        $this->fakeHermes([self::analysis('Acme fixes CVE-2026-1234')]);

        $case = $this->enrich($this->article());

        $this->assertSame('Acme fixes CVE-2026-1234', $case->title);
        Http::assertSentCount(1);
        $this->assertSame(0, ReportReview::query()->count());
        $this->assertSame(300, (new EnrichArticle(1))->timeout);
        $this->assertSame(600, (new RebuildCase(1))->timeout);
    }

    public function test_the_openrouter_analysis_is_reviewed_by_openrouter(): void
    {
        // A IA da análise é a do revisor: com o driver OpenRouter, o parecer e a
        // refação vão para o OpenRouter, e o hermes não é chamado.
        Settings::set('enricher.driver', 'openrouter');
        config(['secmon.enricher.openrouter.api_key' => 'or-key']);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push(self::analysis('Acme fixes CVE-2019-0001'))
                ->push(self::adjust('parte'))
                ->push(self::analysis('Acme fixes CVE-2026-1234'))
                ->push(self::approved()),
            'hermes.test/*' => Http::response([], 500),
        ]);

        $case = $this->enrich($this->article());

        $this->assertSame('Acme fixes CVE-2026-1234', $case->title);
        $this->assertTrue($case->is_published);

        $review = ReportReview::query()->sole();
        $this->assertSame([ReportReview::APPROVED, 'new', 1, 3], [$review->status, $review->mode, $review->adjustments, $review->calls]);
        $this->assertSame(['ajustar', 'aprovado'], array_column($review->rounds, 'verdict'));

        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'hermes.test'));

        // O parecer vai sem o schema da análise; a refação, com ele e com o pedido do revisor.
        Http::assertSent(fn (Request $request) => str_contains((string) ($request['messages'][1]['content'] ?? ''), '"title": "Acme fixes CVE-2019-0001"')
            && ! isset($request['response_format']));
        Http::assertSent(fn (Request $request) => str_contains((string) ($request['messages'][1]['content'] ?? ''), 'fields_to_rewrite: title')
            && $request['response_format']['type'] === 'json_schema');
    }

    public function test_the_heuristic_analysis_has_no_ai_to_review_it(): void
    {
        Settings::set('enricher.driver', 'heuristic');
        Http::fake();

        $case = $this->enrich($this->article());

        $this->assertNotNull($case);
        Http::assertNothingSent();
        $review = ReportReview::query()->sole();
        $this->assertSame([ReportReview::SKIPPED, 'o driver heuristic não fala com o revisor'], [$review->status, $review->reason]);
    }

    public function test_the_update_of_a_published_story_and_the_rebuild_are_reviewed(): void
    {
        $this->assertSame(1500, (new EnrichArticle(1))->timeout);

        $case = NewsCase::query()->create(['title' => 'Acme Gateway flaw', 'summary' => 'Published report.', 'cves' => ['CVE-2026-1234'], 'first_at' => now(), 'latest_at' => now()]);
        $this->partialMock(CaseResolver::class)->shouldReceive('resolve')->andReturn($case);
        $this->fakeHermes(
            [self::analysis('Article title', extra: ['case_title' => 'Acme Gateway flaw patched', 'case_summary' => 'Report with the patch.', 'case_title_pt' => 'Falha corrigida', 'case_summary_pt' => 'Relatório com a correção.'])],
            [self::approved()],
            // O refazer, adiante.
            [self::analysis('Rebuilt title')],
            [self::approved()],
        );

        $this->enrich($this->article());

        $this->assertSame('Acme Gateway flaw patched', $case->refresh()->title);
        $this->assertSame(['update', $case->id], [ReportReview::query()->sole()->mode, ReportReview::query()->sole()->case_id]);
        Http::assertSent(fn (Request $request) => str_contains($request['messages'][1]['content'], 'Published report.')
            && str_contains($request['messages'][1]['content'], '"case_summary": "Report with the patch."'));

        // Refazer com a IA: a matéria de um artigo é reescrita e revisada de novo.
        RebuildCase::dispatchSync($case->id);

        $this->assertSame('Rebuilt title', $case->refresh()->title);
        $this->assertSame(['refazer', 'new'], [ReportReview::query()->latest('id')->first()->origin, ReportReview::query()->latest('id')->first()->mode]);
    }
}
