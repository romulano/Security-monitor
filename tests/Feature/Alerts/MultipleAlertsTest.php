<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\NotificationDelivery;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\ProfilePreview;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Uma empresa, vários alertas: um por produto ou site, no mesmo Slack. */
class MultipleAlertsTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
        $this->mock(ProfilePreview::class)->shouldReceive('run')->andReturn([
            'total' => 0, 'by_level' => [], 'per_week' => 0, 'error' => null, 'examples' => [],
        ]);
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    }

    public function test_client_creates_a_second_alert_and_edits_only_it(): void
    {
        $user = $this->client();
        $first = $this->profile($user, ['name' => 'Loja virtual'], ['nginx']);

        $this->actingAs($user)->post(route('alerts.store'))->assertRedirect();

        $second = $user->alertProfiles()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('draft', $second->status);

        $this->get(route('alerts.interview', ['passo' => 1, 'alerta' => $second->id]))->assertOk()
            ->assertSee('Nome deste alerta')
            ->assertSee('action="'.route('alerts.interview.update', ['passo' => 1, 'alerta' => $second->id]).'"', false);

        $this->put(route('alerts.interview.update', ['passo' => 1, 'alerta' => $second->id]), ['answers' => [
            'alert_name' => 'App', 'company_name' => 'Acme Pagamentos',
        ]])->assertRedirect(route('alerts.interview', ['passo' => 2, 'alerta' => $second->id]));

        $this->assertSame('App', $second->refresh()->name);
        $this->assertSame('Loja virtual', $first->refresh()->name);
        $this->assertSame(['nginx'], $first->techs()->pluck('slug')->all(), 'o primeiro alerta não muda');
    }

    public function test_new_alert_can_start_with_the_answers_of_another_but_not_its_site(): void
    {
        $user = $this->client();
        $first = app(InterviewService::class)->profileFor($user);
        app(InterviewService::class)->save($first, 1, ['alert_name' => 'Loja', 'company_name' => 'Acme', 'sectors' => ['varejo']]);
        $first->answers()->create(['question_key' => 'domain', 'value' => 'loja.acme.com.br']);
        $first->answers()->create(['question_key' => 'has_domain', 'value' => 'sim']);

        $this->actingAs($user)->post(route('alerts.store'), ['copiar' => $first->id])->assertSessionHas('status');

        $copy = $user->alertProfiles()->latest('id')->firstOrFail();
        $values = app(InterviewService::class)->values($copy);
        $this->assertSame('Acme', $copy->company_name);
        $this->assertSame(['varejo'], $copy->sectors);
        $this->assertNull($copy->name);
        $this->assertArrayNotHasKey('domain', $values);
        $this->assertArrayNotHasKey('has_domain', $values);
    }

    public function test_alerts_of_another_client_are_out_of_reach(): void
    {
        $mine = $this->profile($user = $this->client());
        $theirs = $this->profile($this->client());

        $this->actingAs($user);
        $this->get(route('alerts.interview', ['passo' => 1, 'alerta' => $theirs->id]))->assertNotFound();
        $this->put(route('alerts.interview.update', ['passo' => 1, 'alerta' => $theirs->id]), ['answers' => ['company_name' => 'X']])->assertNotFound();
        $this->post(route('alerts.pause', ['alerta' => $theirs->id]))->assertNotFound();
        $this->delete(route('alerts.destroy', ['alerta' => $theirs->id]))->assertNotFound();
        $this->post(route('alerts.store'), ['copiar' => $theirs->id])->assertNotFound();

        $this->assertSame('active', $theirs->refresh()->status);
        $this->assertSame(1, $user->alertProfiles()->count());
        $this->assertTrue($mine->exists);
    }

    public function test_pause_and_delete_act_on_the_chosen_alert(): void
    {
        $user = $this->client();
        $store = $this->profile($user, ['name' => 'Loja virtual']);
        $app = $this->profile($user, ['name' => 'App']);

        $this->actingAs($user)->post(route('alerts.pause', ['alerta' => $app->id]))->assertSessionHas('status');
        $this->assertSame(['active', 'paused'], [$store->refresh()->status, $app->refresh()->status]);

        $this->delete(route('alerts.destroy', ['alerta' => $app->id]))->assertRedirect(route('alerts.news'));
        $this->assertModelMissing($app);
        $this->assertModelExists($store);
    }

    public function test_page_lists_every_alert_and_filters_the_history(): void
    {
        $user = $this->client();
        $store = $this->profile($user, ['name' => 'Loja virtual']);
        $app = $this->profile($user, ['name' => 'App']);
        $draft = AlertProfile::query()->create(['user_id' => $user->id, 'status' => 'draft', 'step_reached' => 3]);

        foreach ([[$store, 'Nginx flaw'], [$app, 'Android flaw']] as [$profile, $title]) {
            AlertMatch::query()->create([
                'profile_id' => $profile->id, 'case_id' => $this->newsCase($title)->id, 'kind' => 'new_case',
                'criticity' => 'high', 'score' => 60, 'reasons' => [], 'delivery_status' => 'sent', 'matched_at' => now(),
            ]);
        }

        $this->actingAs($user)->get(route('alerts.news'))->assertOk()
            ->assertSee('Loja virtual')->assertSee('App')
            ->assertSee('rascunho · passo 3')
            ->assertSee('Nginx flaw')->assertSee('Android flaw')
            ->assertSee(route('alerts.interview', ['passo' => 3, 'alerta' => $draft->id]), false)
            ->assertSee('Novo alerta');

        $this->get(route('alerts.news', ['alerta' => $app->id]))->assertOk()
            ->assertSee('Android flaw')->assertDontSee('Nginx flaw');
    }

    public function test_limit_of_alerts_per_client(): void
    {
        $user = $this->client();

        foreach (range(1, AlertProfile::MAX_PER_USER) as $i) {
            AlertProfile::query()->create(['user_id' => $user->id, 'status' => 'draft']);
        }

        $this->actingAs($user)->post(route('alerts.store'))->assertSessionHas('error');
        $this->assertSame(AlertProfile::MAX_PER_USER, $user->alertProfiles()->count());
    }

    public function test_one_slack_message_when_two_alerts_of_the_client_match_the_same_case(): void
    {
        $user = $this->client();
        $this->slackChannel($user);
        $store = $this->profile($user, ['name' => 'Loja virtual'], ['nginx']);
        $app = $this->profile($user, ['name' => 'App'], [], ['nginx']);
        $case = $this->newsCase('Nginx RCE exploited', 'critical', ['affected_products' => ['nginx']]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        $matches = AlertMatch::query()->get()->keyBy('profile_id');
        $this->assertCount(2, $matches, 'cada alerta guarda o seu registro');
        $this->assertSame('sent', $matches[$store->id]->delivery_status, 'produto afetado pontua mais que menção');
        $this->assertSame(['skipped', 'merged'], [$matches[$app->id]->delivery_status, $matches[$app->id]->delivery_note]);

        $delivery = NotificationDelivery::query()->where('kind', 'realtime')->sole();
        $this->assertStringContainsString('Alerta *Loja virtual + App*', json_encode($delivery->payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_client_with_a_single_alert_keeps_the_old_urls_and_message(): void
    {
        $user = $this->client();
        $this->slackChannel($user);
        $profile = $this->profile($user, [], ['nginx']);
        $case = $this->newsCase('Nginx RCE exploited', 'critical', ['affected_products' => ['nginx']]);

        $this->assertSame([], $profile->urlParams());

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        $payload = json_encode(NotificationDelivery::query()->sole()->payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Alerta *', $payload);
    }

    public function test_admin_sees_each_alert_of_the_client_and_acts_on_the_chosen_one(): void
    {
        $user = $this->client();
        $store = $this->profile($user, ['name' => 'Loja virtual']);
        $app = $this->profile($user, ['name' => 'App', 'company_name' => 'Acme App']);
        $admin = $this->adminUser();

        $this->actingAs($admin)->get(route('admin.alerts.client', $user))->assertOk()
            ->assertSee('Loja virtual')->assertSee('App');
        $this->get(route('admin.alerts.client', [$user, 'alerta' => $app->id]))->assertOk()
            ->assertSee(route('admin.alerts.client.pause', [$user, 'alerta' => $app->id]), false);
        $this->get(route('admin.alerts.clients'))->assertOk()->assertSee('2 alertas');

        $this->post(route('admin.alerts.client.pause', [$user, 'alerta' => $app->id]))->assertSessionHas('status');
        $this->assertSame(['active', 'paused'], [$store->refresh()->status, $app->refresh()->status]);
    }
}
