<?php

namespace Tests\Feature\Alerts;

use App\Models\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Etapa 7: "Adicionar ao Slack" (OAuth v2, escopo incoming-webhook). */
class SlackOAuthTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private function configure(): void
    {
        config(['secmon.alerts.slack.client_id' => '123.456', 'secmon.alerts.slack.client_secret' => 'segredo']);
    }

    private function startFlow(): string
    {
        $redirect = $this->get(route('alerts.slack.oauth'))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    public function test_without_credentials_the_button_is_off(): void
    {
        $this->actingAs($this->client());

        $this->get(route('alerts.slack'))->assertSee('Indisponível: o app do Security Monitor no Slack ainda não foi configurado');
        $this->get(route('alerts.slack.oauth'))->assertNotFound();
        $this->get(route('alerts.slack.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();
    }

    public function test_redirects_to_slack_with_state_and_only_the_webhook_scope(): void
    {
        $this->configure();
        $this->actingAs($this->client());

        $this->get(route('alerts.slack'))->assertSee(route('alerts.slack.oauth'));
        $location = $this->get(route('alerts.slack.oauth'))->headers->get('Location');

        $this->assertStringStartsWith('https://slack.com/oauth/v2/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['123.456', 'incoming-webhook', route('alerts.slack.callback')], [$query['client_id'], $query['scope'], $query['redirect_uri']]);
        $this->assertSame(40, strlen($query['state']));
        $this->assertSame($query['state'], session('slack_oauth_state'));
    }

    public function test_successful_install_saves_the_channel_and_sends_a_test(): void
    {
        $this->configure();
        Http::fake([
            'slack.com/api/oauth.v2.access' => Http::response([
                'ok' => true,
                'access_token' => 'xoxb-secreto',
                'team' => ['name' => 'Acme'],
                'incoming_webhook' => ['url' => 'https://hooks.slack.com/services/T1/B2/abcdef', 'channel' => '#seguranca-alertas', 'channel_id' => 'C1'],
            ]),
            'hooks.slack.com/*' => Http::response('ok'),
            // Desconectar revoga o token (o teste confere a chamada); sem este stub ela ia ao Slack de verdade.
            'slack.com/api/auth.revoke' => Http::response(['ok' => true]),
        ]);
        $user = $this->client();
        $this->actingAs($user);
        $state = $this->startFlow();

        $this->get(route('alerts.slack.callback', ['code' => 'abc', 'state' => $state]))
            ->assertRedirect(route('alerts.slack'))
            ->assertSessionHas('status', 'Slack conectado em #seguranca-alertas. Mandamos uma mensagem de teste.');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://slack.com/api/oauth.v2.access'
            && $r['code'] === 'abc' && $r['client_secret'] === 'segredo' && $r['redirect_uri'] === route('alerts.slack.callback'));

        $channel = $user->slackChannel()->firstOrFail();
        $this->assertSame(['oauth', 'Acme', '#seguranca-alertas', 'active'], [$channel->method, $channel->slack_team, $channel->slack_channel, $channel->status]);
        $this->assertSame('xoxb-secreto', $channel->bot_token);
        $this->assertStringNotContainsString('xoxb', DB::table('notification_channels')->value('bot_token'));
        $this->assertSame('sent', NotificationDelivery::query()->where('kind', 'test')->value('status'));

        // O state é de uso único.
        $this->get(route('alerts.slack.callback', ['code' => 'abc', 'state' => $state]))->assertSessionHas('error');

        // Desconectar revoga o token no Slack.
        $this->delete(route('alerts.slack.destroy'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://slack.com/api/auth.revoke' && $r['token'] === 'xoxb-secreto');
        $this->assertModelMissing($channel);
    }

    public function test_forged_state_cancel_and_bad_answers_are_refused(): void
    {
        $this->configure();
        $user = $this->client();
        $this->actingAs($user);

        $this->startFlow();
        $this->get(route('alerts.slack.callback', ['code' => 'abc', 'state' => str_repeat('x', 40)]))->assertSessionHas('error');

        $state = $this->startFlow();
        $this->get(route('alerts.slack.callback', ['error' => 'access_denied', 'state' => $state]))->assertSessionHas('error', 'Conexão cancelada no Slack.');

        Http::fake(['slack.com/api/oauth.v2.access' => Http::sequence()
            ->push(['ok' => false, 'error' => 'invalid_code'])
            // Mesmo com ok: true, um webhook fora do hooks.slack.com não é aceito.
            ->push(['ok' => true, 'incoming_webhook' => ['url' => 'https://evil.io/hook']])]);

        $state = $this->startFlow();
        $this->get(route('alerts.slack.callback', ['code' => 'velho', 'state' => $state]))->assertSessionHas('error', fn ($m) => str_contains($m, 'invalid_code'));

        $state = $this->startFlow();
        $this->get(route('alerts.slack.callback', ['code' => 'abc', 'state' => $state]))->assertSessionHas('error', fn ($m) => str_contains($m, 'resposta inválida'));

        $this->assertNull($user->slackChannel()->first());
    }
}
