<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\Article;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\ProfileIndex;
use App\Services\Crawler\CaseWriter;
use App\Services\Enrichment\EnrichmentResult;
use App\Services\Search\CaseIndex;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Etapa 3: caso novo → percolator → MatchEngine → alert_matches → roteamento. */
class MatchingTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private FakeProfileIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = new FakeProfileIndex;
        $this->app->instance(ProfileIndex::class, $this->index);
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    }

    private function enrichment(string $title, string $criticity, array $indicators, bool $newInfo = true): EnrichmentResult
    {
        return new EnrichmentResult(
            title: $title,
            summary: "Resumo: {$title}",
            criticity: $criticity,
            indicators: EnrichmentResult::indicatorGroups($indicators),
            isNewInfo: $newInfo,
            newContext: 'Nova fonte confirma exploração.',
        );
    }

    private function rawArticle(string $title): Article
    {
        $url = 'https://example.com/'.md5($title.microtime());

        return Article::query()->create([
            'url' => $url, 'url_hash' => hash('sha256', $url), 'source_host' => 'example.com',
            'original_title' => $title, 'status' => 'pending', 'collected_at' => now(),
        ]);
    }

    public function test_case_writer_only_queues_matching_when_someone_can_receive(): void
    {
        Queue::fake();
        $writer = app(CaseWriter::class);

        $writer->createCase($this->rawArticle('Sem perfis'), $this->enrichment('Sem perfis', 'high', []));
        Queue::assertNotPushed(MatchCaseToProfiles::class);

        $this->profile(null, [], ['laravel']);
        $case = $writer->createCase($this->rawArticle('Com perfil'), $this->enrichment('Com perfil', 'high', []));
        Queue::assertPushed(MatchCaseToProfiles::class, fn ($job) => $job->caseId === $case->id && $job->kind === 'new_case' && $job->queue === 'alerts');

        // Reanálise no lugar não alerta de novo.
        Queue::fake();
        $writer->createCase($case->articles()->first(), $this->enrichment('Com perfil', 'critical', []), $case);
        Queue::assertNotPushed(MatchCaseToProfiles::class);

        Settings::set('alerts.enabled', false);
        $writer->createCase($this->rawArticle('Desligado'), $this->enrichment('Desligado', 'high', []));
        Queue::assertNotPushed(MatchCaseToProfiles::class);
    }

    public function test_new_case_from_the_crawler_becomes_an_alert(): void
    {
        $profile = $this->profile(null, [], ['laravel']);

        $case = app(CaseWriter::class)->createCase(
            $this->rawArticle('Laravel RCE'),
            $this->enrichment('Falha no Laravel permite RCE', 'critical', ['affected_products' => ['Laravel 11'], 'cves' => ['CVE-2026-9999']]),
        );

        $match = AlertMatch::query()->where('profile_id', $profile->id)->sole();
        $this->assertSame($case->id, $match->case_id);
        $this->assertSame('new_case', $match->kind);
        $this->assertSame(80, $match->score);
        $this->assertNull($match->suppressed_reason);
        $this->assertSame('skipped', $match->delivery_status, 'sem canal: fica só em Alertas');
        $this->assertSame('no_channel', $match->delivery_note);
        $this->assertSame(['Laravel', 'CVE-2026-9999'], collect($match->reasons)->pluck('label')->all());
    }

    public function test_percolator_candidates_limit_who_is_evaluated(): void
    {
        $a = $this->profile(null, [], ['redis']);
        $b = $this->profile(null, [], ['redis']);
        $case = $this->newsCase('Redis flaw', 'high', ['affected_products' => ['Redis']]);

        $this->index->candidates = [$b->id];
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        $this->assertSame([$b->id], AlertMatch::query()->pluck('profile_id')->all());

        // Percolator fora do ar: avalia todos.
        $this->index->candidates = null;
        $other = $this->newsCase('Redis again', 'high', ['affected_products' => ['Redis']]);
        MatchCaseToProfiles::dispatchSync($other->id, $other->articles()->value('id'));

        $this->assertEqualsCanonicalizing([$a->id, $b->id], AlertMatch::query()->where('case_id', $other->id)->pluck('profile_id')->all());
    }

    public function test_suppressed_matches_are_recorded_but_not_alerts(): void
    {
        $profile = $this->profile($user = $this->client(), ['min_criticity' => 'critical'], ['nginx']);
        $this->slackChannel($user);
        $case = $this->newsCase('Nginx minor', 'medium', ['affected_products' => ['nginx']]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        $match = AlertMatch::query()->sole();
        $this->assertSame('below_level', $match->suppressed_reason);
        $this->assertSame('skipped', $match->delivery_status);
        Http::assertNothingSent();
        $this->assertSame(0, $profile->matches()->relevant()->count());
    }

    public function test_same_job_twice_records_one_match(): void
    {
        $this->profile(null, [], ['nginx']);
        $case = $this->newsCase('Nginx', 'high', ['affected_products' => ['nginx']]);
        $articleId = $case->articles()->value('id');

        MatchCaseToProfiles::dispatchSync($case->id, $articleId);
        MatchCaseToProfiles::dispatchSync($case->id, $articleId);

        $this->assertSame(1, AlertMatch::query()->count());
    }

    public function test_updates_follow_the_client_choice_unless_criticity_rises(): void
    {
        $profile = $this->profile(null, ['notify_case_updates' => false], ['fortinet']);
        $case = $this->newsCase('FortiOS flaw', 'high', ['vendors' => ['Fortinet']]);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        // Nova fonte, mesma criticidade: o cliente não quer atualizações.
        $second = $this->article($case);
        MatchCaseToProfiles::dispatchSync($case->id, $second->id, 'case_update');
        $this->assertSame(1, AlertMatch::query()->count());

        // A criticidade subiu: avisa mesmo assim.
        $case->update(['criticity' => 'critical']);
        $third = $this->article($case);
        MatchCaseToProfiles::dispatchSync($case->id, $third->id, 'case_update');

        $update = AlertMatch::query()->where('article_id', $third->id)->sole();
        $this->assertSame('case_update', $update->kind);
        $this->assertSame('critical', $update->criticity);

        // Com atualizações ligadas, qualquer informação nova avisa.
        $profile->update(['notify_case_updates' => true]);
        $fourth = $this->article($case);
        MatchCaseToProfiles::dispatchSync($case->id, $fourth->id, 'case_update');
        $this->assertSame(3, AlertMatch::query()->count());
    }

    public function test_paused_and_draft_profiles_receive_nothing(): void
    {
        $this->profile(null, ['status' => 'paused'], ['nginx']);
        $this->profile(null, ['status' => 'draft'], ['nginx']);
        $case = $this->newsCase('Nginx', 'critical', ['affected_products' => ['nginx']]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        $this->assertSame(0, AlertMatch::query()->count());
    }

    public function test_router_holds_or_skips_according_to_the_channel(): void
    {
        $route = function (array $channel, string $criticity = 'high') {
            AlertMatch::query()->delete();
            AlertProfile::query()->update(['status' => 'draft']); // só o perfil da rodada recebe
            $user = $this->client();
            $this->profile($user, [], ['nginx']);
            $this->slackChannel($user, $channel);
            $case = $this->newsCase('Nginx', $criticity, ['affected_products' => ['nginx']]);
            MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

            return AlertMatch::query()->sole();
        };

        $off = $route(['realtime' => false]);
        $this->assertSame(['skipped', 'realtime_off'], [$off->delivery_status, $off->delivery_note]);

        $broken = $route(['status' => 'broken']);
        $this->assertSame(['skipped', 'channel_broken'], [$broken->delivery_status, $broken->delivery_note]);

        // Silêncio o dia todo (00:00–23:59): high espera, critical passa.
        $held = $route(['quiet_start' => '00:00', 'quiet_end' => '23:59']);
        $this->assertSame(['held', 'quiet_hours'], [$held->delivery_status, $held->delivery_note]);
        $this->assertTrue($held->release_at->isFuture());

        $critical = $route(['quiet_start' => '00:00', 'quiet_end' => '23:59'], 'critical');
        $this->assertSame('sent', $critical->delivery_status);
    }

    public function test_activating_the_profile_backfills_the_last_week_without_notifying(): void
    {
        $user = $this->client();
        $this->slackChannel($user);
        $old = $this->newsCase('Nginx antigo', 'high', ['affected_products' => ['nginx']], ['latest_at' => now()->subDays(10)]);
        $recent = $this->newsCase('Nginx recente', 'high', ['affected_products' => ['nginx']], ['latest_at' => now()->subDays(2)]);
        $this->actingAs($user);

        $service = app(InterviewService::class);
        $profile = $service->profileFor($user);
        $service->save($profile, 1, ['company_name' => 'Acme']);
        $service->save($profile, 3, ['web_servers' => ['nginx']]);

        $this->post(route('alerts.interview.finish'))->assertRedirect(route('alerts.news'));

        $match = AlertMatch::query()->sole();
        $this->assertSame($recent->id, $match->case_id);
        $this->assertSame(['skipped', 'backfill'], [$match->delivery_status, $match->delivery_note]);
        Http::assertNothingSent();
        $this->assertNotSame($old->id, $match->case_id);
    }

    public function test_my_alerts_lists_matches_and_takes_feedback(): void
    {
        $user = $this->client();
        $profile = $this->profile($user, ['language' => 'pt'], ['nginx']);
        $case = $this->newsCase('Nginx flaw', 'high', ['affected_products' => ['nginx']], ['title_pt' => 'Falha no Nginx']);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));
        $match = AlertMatch::query()->sole();

        $this->actingAs($user)->get(route('alerts.news'))
            ->assertOk()
            ->assertSee('Falha no Nginx')
            ->assertSee('Nginx')
            ->assertSee('sem canal conectado');

        $this->post(route('alerts.feedback', $match), ['feedback' => 'irrelevant'])->assertSessionHas('status');
        $this->assertSame('irrelevant', $match->refresh()->feedback);

        $this->get(route('alerts.news', ['filtro' => 'nao-relevantes']))->assertSee('Falha no Nginx');
        $this->get(route('alerts.news', ['filtro' => 'na-hora']))->assertDontSee('Falha no Nginx');

        // Ninguém mexe no alerta dos outros.
        $this->actingAs($this->client())->post(route('alerts.feedback', $match), ['feedback' => 'relevant'])->assertNotFound();
        $this->assertSame('irrelevant', $match->refresh()->feedback);
    }

    public function test_slack_feedback_link_needs_a_valid_signature(): void
    {
        $profile = $this->profile(null, [], ['nginx']);
        $case = $this->newsCase('Nginx', 'high', ['affected_products' => ['nginx']]);
        $match = AlertMatch::query()->create(['profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'matched_at' => now()]);

        $this->get(route('alerts.feedback.signed', $match))->assertForbidden();
        $this->assertNull($match->refresh()->feedback);

        $this->get(URL::temporarySignedRoute('alerts.feedback.signed', now()->addDay(), ['match' => $match->id]))
            ->assertOk()
            ->assertSee('marcamos como não relevante');
        $this->assertSame('irrelevant', $match->refresh()->feedback);
    }

    public function test_relevant_link_from_the_message_marks_the_alert(): void
    {
        $profile = $this->profile(null, [], ['nginx']);
        $case = $this->newsCase('Nginx', 'high', ['affected_products' => ['nginx']]);
        $match = AlertMatch::query()->create(['profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'matched_at' => now()]);

        $this->get(route('alerts.feedback.signed.relevant', $match))->assertForbidden();
        $this->assertNull($match->refresh()->feedback);

        $this->get(URL::temporarySignedRoute('alerts.feedback.signed.relevant', now()->addDay(), ['match' => $match->id]))
            ->assertOk()
            ->assertSee('marcamos como relevante');
        $this->assertSame('relevant', $match->refresh()->feedback);
        $this->assertNotNull($match->feedback_at);
    }

    public function test_rematch_command_records_without_notifying_by_default(): void
    {
        $user = $this->client();
        $this->profile($user, [], ['nginx']);
        $this->slackChannel($user);
        $this->newsCase('Nginx 1', 'high', ['affected_products' => ['nginx']]);
        $this->newsCase('Nada a ver', 'high', ['affected_products' => ['Oracle']]);

        $this->artisan('secmon:alerts-match', ['--since' => '24h', '--sync' => true])->assertSuccessful();

        $this->assertSame(1, AlertMatch::query()->count());
        $this->assertSame('backfill', AlertMatch::query()->value('delivery_note'));
        Http::assertNothingSent();

        $this->artisan('secmon:alerts-match', ['--since' => 'ontem'])->assertExitCode(2);
    }
}
