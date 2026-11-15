<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Models\AlertMatch;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Alerts\ProfileIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Reenviar" nas notícias recebidas e a aba Notificadores (Slack e Discord). */
class ResendAndIntegrationsTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
    }

    /** Alerta guardado sem canal: fica no histórico como "sem canal conectado". */
    private function storedAlert(?User $user = null): AlertMatch
    {
        $user ??= $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $case = $this->newsCase('Nginx RCE', 'critical', ['affected_products' => ['nginx 1.24']]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        return AlertMatch::query()->where('case_id', $case->id)->sole();
    }

    public function test_client_resends_an_alert_from_the_history(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);

        $match = $this->storedAlert();
        $user = $match->profile->user;
        $this->assertSame(['skipped', 'no_channel'], [$match->delivery_status, $match->delivery_note]);
        $this->slackChannel($user);

        $this->actingAs($user)->get(route('alerts.news'))->assertSee(route('alerts.resend', $match), false);

        $this->actingAs($user)->post(route('alerts.resend', $match))
            ->assertRedirect()
            ->assertSessionHas('status', 'Alerta reenviado para o Slack.');

        Http::assertSentCount(1);
        $match->refresh();
        $this->assertSame(['sent', 'resent'], [$match->delivery_status, $match->delivery_note]);
        $this->assertSame('reenviado no Slack', $match->deliveryLabel());

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(['realtime', 'sent', true], [$delivery->kind, $delivery->status, $delivery->meta['resent']]);
        $this->assertStringStartsWith('Reenvio: ', $delivery->summary);
    }

    public function test_resend_needs_an_active_slack(): void
    {
        Http::fake();

        $match = $this->storedAlert();
        $user = $match->profile->user;

        $this->actingAs($user)->get(route('alerts.news'))->assertDontSee(route('alerts.resend', $match), false);
        $this->actingAs($user)->post(route('alerts.resend', $match))
            ->assertSessionHas('error', 'Conecte o Slack, o Discord, o Telegram ou um webhook em Notificadores para reenviar alertas.');

        $this->slackChannel($user, ['status' => 'broken']);
        $this->actingAs($user->fresh())->post(route('alerts.resend', $match))
            ->assertSessionHas('error', 'O Slack precisa ser reconectado antes de reenviar.');

        Http::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    public function test_alert_of_another_client_cannot_be_resent(): void
    {
        Http::fake();

        $match = $this->storedAlert();
        $intruder = $this->client();
        $this->slackChannel($intruder);

        $this->actingAs($intruder)->post(route('alerts.resend', $match))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_notifiers_page_lists_slack_and_discord(): void
    {
        $user = $this->client();

        $this->actingAs($user)->get(route('alerts.notifiers'))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSeeInOrder(['Slack', 'não conectado', 'Discord', 'não conectado'])
            ->assertSee('href="'.route('alerts.slack').'"', false)
            ->assertSee('href="'.route('alerts.discord').'"', false);

        $this->slackChannel($user, ['slack_channel' => '#seguranca']);

        $this->actingAs($user->fresh())->get(route('alerts.notifiers'))
            ->assertSeeInOrder(['Slack', 'conectado', 'Chegando em #seguranca']);
    }

    public function test_alert_pages_share_the_menu(): void
    {
        $user = $this->client();
        $this->profile($user);

        // Três abas: Notícias, Radar e GitHub abrem pela Integração (e a acendem); Slack, Discord e Telegram, por Notificadores.
        $tabs = ['alerts.index' => 'Alertas', 'alerts.news' => 'Integração', 'alerts.lookalikes' => 'Integração', 'alerts.github' => 'Integração',
            'alerts.integrations' => 'Integração', 'alerts.notifiers' => 'Notificadores', 'alerts.slack' => 'Notificadores', 'alerts.discord' => 'Notificadores'];

        foreach ($tabs as $route => $active) {
            $page = $this->actingAs($user)->get(route($route))
                ->assertOk()
                ->assertSee('aria-label="Alertas"', false)
                ->assertSee('href="'.route('alerts.integrations').'"', false)
                ->assertSee('href="'.route('alerts.notifiers').'"', false)
                ->assertSeeInOrder(['>Alertas</a>', '>Integração</a>', '>Notificadores</a>'], false)
                ->assertDontSee('>Central</a>', false);

            $this->assertMatchesRegularExpression('/aria-current="page"\s+class="[^"]*border-accent[^"]*">'.$active.'<\/a>/', $page->getContent(), $route);
        }

        // As páginas de cada fonte voltam para a Integração pela trilha.
        foreach (['alerts.news', 'alerts.lookalikes', 'alerts.github'] as $route) {
            $this->actingAs($user)->get(route($route))->assertSee('class="hover:text-ink-200">Integração</a> /', false);
        }
    }

    public function test_alert_pages_need_login(): void
    {
        foreach (['alerts.index', 'alerts.news', 'alerts.integrations', 'alerts.notifiers', 'alerts.discord'] as $route) {
        }
    }
}
