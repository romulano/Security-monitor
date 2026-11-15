<?php

namespace Tests\Feature;

use App\Jobs\EnrichArticle;
use App\Jobs\RebuildCase;
use App\Models\Article;
use App\Models\NewsCase;
use App\Models\ReportReview;
use App\Models\User;
use App\Services\Review\ReviewOutcome;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** O revisor do relatório para o admin: a página da matéria e o "IA agora" de Coletas. */
class ReportReviewAdminTest extends TestCase
{
    use RefreshDatabase;

    private function story(string $title = 'Acme Gateway flaw'): NewsCase
    {
        return NewsCase::query()->create(['title' => $title, 'summary' => 'Report.', 'first_at' => now(), 'latest_at' => now()]);
    }

    private function article(?NewsCase $case = null): Article
    {
        return Article::query()->create([
            'case_id' => $case?->id,
            'url' => 'https://example.com/'.uniqid(),
            'url_hash' => hash('sha256', uniqid('', true)),
            'source_host' => 'example.com',
            'original_title' => 'Acme patches Gateway flaw',
            'content' => 'Acme fixed CVE-2026-1234.',
            'collected_at' => now()->subMinutes(5),
            'status' => $case ? 'enriched' : 'pending',
        ]);
    }

    private static function pending(): ReviewOutcome
    {
        $round = fn (string $text) => ['verdict' => 'ajustar', 'scope' => 'parte', 'note' => '', 'problems' => [
            ['field' => 'cves', 'excerpt' => 'CVE-2019-0001', 'instruction' => $text],
        ]];

        return new ReviewOutcome(ReportReview::PENDING, 'new', [$round('Primeiro pedido.'), $round('Segundo pedido.'), $round('Tirar a CVE-2019-0001, que é só contexto.')], 2, 5, 'reprovado no parecer final');
    }

    public function test_only_the_admin_sees_the_pending_review_on_the_story(): void
    {
        $case = $this->story();
        self::pending()->record($case, $this->article($case));

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('feed.show', $case->uuid))
            ->assertOk()
            ->assertSee('Revisão pendente')
            ->assertSee('2 ajuste(s), 5 chamada(s) a mais')
            ->assertSee('reprovado no parecer final')
            ->assertSee('Tirar a CVE-2019-0001, que é só contexto.')
            ->assertSee('Segundo pedido.');

        // O leitor vê a matéria como ela saiu, sem a marca.
        Auth::logout();
        $this->get(route('feed.show', $case->uuid))->assertOk()->assertDontSee('Revisão pendente')->assertDontSee('revisor');
        $this->actingAs(User::factory()->create())->get(route('feed.show', $case->uuid))->assertOk()->assertDontSee('Revisão pendente');
    }

    public function test_a_newer_approved_version_takes_the_mark_away(): void
    {
        $case = $this->story();
        self::pending()->record($case, $this->article($case));
        (new ReviewOutcome(ReportReview::APPROVED, 'update', [['verdict' => 'aprovado', 'scope' => null, 'problems' => [], 'note' => 'Fiel à fonte.']], calls: 1))->record($case, $this->article($case));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('feed.show', $case->uuid))
            ->assertSee('Aprovada pelo revisor')
            ->assertSee('atualização na coleta')
            ->assertSee('Fiel à fonte.')
            // A versão anterior fica no histórico.
            ->assertSee('Revisão pendente · matéria nova na coleta');

        $this->actingAs($admin)->get(route('admin.collections'))->assertOk()->assertDontSee('Revisão pendente (');
    }

    public function test_ai_now_shows_the_round_and_what_the_reviewer_did(): void
    {
        Settings::set('prompts.revisor', true);
        $pendingCase = $this->story('Zeta breach story');
        self::pending()->record($pendingCase, $this->article($pendingCase));
        ReviewOutcome::skipped('new', 'o revisor falhou: hermes HTTP 502')->record($this->story(), $this->article());

        $running = $this->article();
        $rebuilt = $this->story('Rebuilt story');
        Cache::put('report-review:article:'.$running->id, ['step' => 'parecer', 'round' => 2, 'rounds' => 3]);
        Cache::put('report-review:case:'.$rebuilt->id, ['step' => 'refação', 'round' => 1, 'rounds' => 3]);

        foreach ([new EnrichArticle($running->id), new RebuildCase($rebuilt->id)] as $job) {
            DB::table('jobs')->insert([
                'queue' => 'enrich', 'attempts' => 1, 'reserved_at' => now()->subMinute()->timestamp, 'available_at' => now()->subMinutes(2)->timestamp, 'created_at' => now()->subMinutes(2)->timestamp,
                'payload' => json_encode(['uuid' => (string) Str::uuid(), 'displayName' => get_class($job), 'timeout' => $job->timeout, 'data' => ['commandName' => get_class($job), 'command' => serialize($job)]]),
            ]);
        }

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.collections'))
            ->assertOk()
            // O job ganha o tempo do revisor: 5 + 20 min.
            ->assertSee('do limite de 25 min')
            ->assertSeeInOrder(['Análise do artigo', 'Revisor do relatório:', 'parecer 2 de até 3'])
            ->assertSeeInOrder(['Relatório da matéria', 'Revisor do relatório:', 'a análise refaz o ajuste 1'])
            ->assertSee('0 aprovada(s)')
            ->assertSee('1 com revisão pendente')
            ->assertSee('1 pulada(s)')
            ->assertSee('5 chamada(s) a mais')
            ->assertSee('Revisão pendente (1):')
            ->assertSee('Zeta breach story');
    }

    public function test_ai_now_says_when_the_reviewer_is_off(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.collections'))
            ->assertOk()
            ->assertSee('desligado')
            ->assertSee(route('admin.prompts.show', 'revisor'), false);
    }
}
