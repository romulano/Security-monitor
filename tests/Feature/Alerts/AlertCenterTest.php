<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Jobs\SendSlackMessage;
use App\Models\AlertMatch;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Alerts\DigestBuilder;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Slack\MessageBuilder as SlackMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O que cada app recebe (notícias, Radar, GitHub) e a pausa do envio sem
 * desconectar, na aba Notificadores (a antiga Central), no envio na hora, nos
 * segurados, no reenvio, no resumo e no job de entrega. O Radar está em
 * LookalikeAlertsTest.
 */
class AlertCenterTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private const DISCORD_HOOK = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyzABCDEFGHIJ_-12';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok'),
            'discord.com/*' => Http::response(['id' => '1']),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function discordChannel(User $user, array $attributes = []): NotificationChannel
    {
        return NotificationChannel::query()->create($attributes + [
            'user_id' => $user->id,
            'type' => 'discord',
            'method' => 'webhook',
            'webhook_url' => self::DISCORD_HOOK,
            'status' => 'active',
            'realtime' => true,
            'digest' => true,
            'digest_time' => '07:00',
            'digest_days' => [1, 2, 3, 4, 5, 6, 7],
            'timezone' => 'America/Sao_Paulo',
        ]);
    }

    /** Cliente com alerta de nginx ativo; a notícia sai assim que casa (fila síncrona nos testes). */
    private function clientWithProfile(): User
    {
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);

        return $user;
    }

    private function nginxNews(string $title = 'Nginx RCE', string $criticity = 'critical'): AlertMatch
    {
        $case = $this->newsCase($title, $criticity, ['affected_products' => ['nginx 1.24']]);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        return AlertMatch::query()->where('case_id', $case->id)->sole();
    }

    private function sentTo(string $host): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $host))->count();
    }

    public function test_notifiers_show_each_app_with_its_types_and_who_receives_what(): void
    {
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user);
        $discord = $this->discordChannel($user, ['muted_types' => ['news']]);

        $this->actingAs($user)->get(route('alerts.notifiers'))
            ->assertOk()
            ->assertSee('Notificadores')
            ->assertSee('Vai para o Slack', false)
            ->assertSee('Vai para o Slack e o Discord', false) // Radar: os dois
            ->assertSee('O Radar ainda não vigia nenhum domínio seu.')
            ->assertSee('name="tipos['.$slack->id.'][news]"', false)
            ->assertSee('name="tipos['.$discord->id.'][radar]"', false)
            ->assertSee(route('alerts.center.pause', $discord), false)
            ->assertSee('Pausar envio')
            // O Telegram, que falta, aparece para conectar depois dos conectados.
            ->assertSeeInOrder(['Salvar o que cada app recebe', 'Conectar outro app', 'Telegram', 'não conectado']);

        // Sem app conectado: só os três apps para conectar, sem o formulário.
        $this->actingAs($this->client())->get(route('alerts.notifiers'))
            ->assertOk()
            ->assertSeeInOrder(['Slack', 'não conectado', 'Discord', 'não conectado', 'Telegram', 'não conectado'])
            ->assertDontSee('Salvar o que cada app recebe');
    }

    public function test_the_old_center_address_leads_to_the_notifiers(): void
    {
        $this->actingAs($this->client())->get('/alertas/central')
            ->assertStatus(301)
            ->assertRedirect('/alertas/notificadores');
    }

    public function test_saving_from_the_notifiers_page_comes_back_to_it_with_the_message(): void
    {
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user);

        $this->actingAs($user)->from(route('alerts.notifiers'))
            ->put(route('alerts.center.update'), ['tipos' => [$slack->id => ['news' => '1', 'radar' => '0', 'github' => '0']]])
            ->assertRedirect(route('alerts.notifiers'))
            ->assertSessionHas('status', 'Salvo. Vale a partir do próximo alerta.');

        $this->assertSame(['radar', 'github'], $slack->refresh()->muted_types);
        $this->actingAs($user)->get(route('alerts.notifiers'))
            ->assertSee('Salvo. Vale a partir do próximo alerta.')
            ->assertDontSee('name="tipos['.$slack->id.'][radar]" value="1" checked', false);
    }

    public function test_saving_the_center_only_touches_the_clients_own_apps(): void
    {
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user);
        $discord = $this->discordChannel($user);
        $other = $this->slackChannel($this->client());

        $this->actingAs($user)->put(route('alerts.center.update'), ['tipos' => [
            $slack->id => ['news' => '0', 'radar' => '1', 'github' => '1'],
            $discord->id => ['news' => '1', 'radar' => '0', 'github' => '0'],
            $other->id => ['news' => '0', 'radar' => '0', 'github' => '0'],
        ]])->assertRedirect()->assertSessionHas('status', 'Salvo. Vale a partir do próximo alerta.');

        $this->assertSame(['news'], $slack->refresh()->muted_types);
        $this->assertSame(['radar', 'github'], $discord->refresh()->muted_types);
        $this->assertSame([], $other->refresh()->muted_types, 'canal de outro cliente fica como estava');
        $this->assertSame('só Radar de domínios e GitHub', $slack->typesLabel());
        $this->assertSame('só notícias', $discord->typesLabel());

        // Tipo que não existe é recusado.
        $this->actingAs($user)->put(route('alerts.center.update'), ['tipos' => [$slack->id => ['email' => '1']]])
            ->assertSessionHasErrors('tipos.'.$slack->id);
    }

    public function test_pausing_keeps_the_app_connected_and_only_the_owner_can_do_it(): void
    {
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user);

        $this->actingAs($user)->post(route('alerts.center.pause', $slack))
            ->assertRedirect()
            ->assertSessionHas('status', 'Envio para o Slack pausado. Nada sai para lá até você retomar; os alertas continuam guardados no Security Monitor.');

        $slack->refresh();
        $this->assertTrue($slack->isPaused());
        $this->assertSame('active', $slack->status, 'pausar não desconecta');

        foreach ([route('alerts.slack'), route('alerts.notifiers'), route('alerts.news')] as $page) {
            $this->actingAs($user)->get($page)->assertOk()->assertSee('pausado');
        }

        $this->actingAs($this->client())->post(route('alerts.center.pause', $slack))->assertNotFound();
        $this->assertTrue($slack->refresh()->isPaused());

        $this->actingAs($user)->post(route('alerts.center.pause', $slack))->assertSessionHas('status', 'Envio para o Slack retomado.');
        $this->assertFalse($slack->refresh()->isPaused());
    }

    public function test_news_go_only_to_the_apps_with_news_on(): void
    {
        $user = $this->clientWithProfile();
        $this->slackChannel($user);
        $discord = $this->discordChannel($user, ['muted_types' => ['news']]);

        $match = $this->nginxNews();

        $this->assertSame('sent', $match->delivery_status);
        $this->assertSame(1, $this->sentTo('hooks.slack.com'));
        $this->assertSame(0, $this->sentTo('discord.com'));
        $this->assertSame(0, NotificationDelivery::query()->where('channel_id', $discord->id)->count());
    }

    public function test_paused_or_news_off_everywhere_keeps_the_alert_only_in_the_history(): void
    {
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user, ['paused_at' => now()]);
        $discord = $this->discordChannel($user, ['muted_types' => ['news']]);

        $match = $this->nginxNews('Nginx A');
        $this->assertSame(['skipped', 'channel_paused'], [$match->delivery_status, $match->delivery_note]);
        $this->assertSame('envio pausado em Notificadores', $match->deliveryLabel());

        $slack->update(['paused_at' => null, 'muted_types' => ['news']]);
        $match = $this->nginxNews('Nginx B');
        $this->assertSame(['skipped', 'type_off'], [$match->delivery_status, $match->delivery_note]);
        $this->assertSame('notícias desligadas em Notificadores', $match->deliveryLabel());

        Http::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::query()->count());

        $this->actingAs($user)->get(route('alerts.news'))->assertSee('Nenhum app recebe notícias.');
        $this->assertNotNull($discord);
    }

    public function test_held_alert_is_not_released_to_an_app_paused_meanwhile(): void
    {
        Carbon::setTestNow('2026-10-20 03:00:00'); // 00:00 em São Paulo
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user, ['quiet_start' => '22:00', 'quiet_end' => '07:00']);

        $match = $this->nginxNews('Nginx A', 'high');
        $this->assertSame(['held', 'quiet_hours'], [$match->delivery_status, $match->delivery_note]);

        $slack->update(['paused_at' => now()]);
        Carbon::setTestNow('2026-10-20 10:05:00'); // 07:05
        $this->artisan('secmon:alerts-flush')->assertSuccessful();

        $this->assertSame(['skipped', 'channel_paused'], [$match->refresh()->delivery_status, $match->delivery_note]);
        Http::assertNothingSent();
    }

    public function test_queued_message_is_dropped_when_the_app_is_paused_but_the_test_still_goes(): void
    {
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user, ['realtime' => false]);
        $match = $this->nginxNews();
        $this->assertSame('realtime_off', $match->delivery_note);

        // Entrou na fila e o cliente pausou antes do worker pegar.
        $queued = NotificationDelivery::query()->create(['channel_id' => $slack->id, 'user_id' => $user->id, 'kind' => 'realtime', 'status' => 'queued', 'match_id' => $match->id, 'payload' => ['text' => 'x'], 'summary' => 'x']);
        $test = NotificationDelivery::query()->create(['channel_id' => $slack->id, 'user_id' => $user->id, 'kind' => 'test', 'status' => 'queued', 'payload' => ['text' => 'teste'], 'summary' => 'Mensagem de teste']);
        $slack->update(['paused_at' => now()]);

        SendSlackMessage::dispatchSync($queued->id);
        SendSlackMessage::dispatchSync($test->id);

        $this->assertSame(['skipped', 'envio pausado em Notificadores'], [$queued->refresh()->status, $queued->error]);
        $this->assertSame(['skipped', 'channel_paused'], [$match->refresh()->delivery_status, $match->delivery_note]);
        $this->assertSame('sent', $test->refresh()->status, 'o teste sai mesmo pausado');
        $this->assertSame(1, $this->sentTo('hooks.slack.com'));

        // Notícias desligadas depois de entrar na fila: também não sai.
        $slack->update(['paused_at' => null, 'muted_types' => ['news']]);
        $again = NotificationDelivery::query()->create(['channel_id' => $slack->id, 'user_id' => $user->id, 'kind' => 'realtime', 'status' => 'queued', 'match_id' => $match->id, 'payload' => ['text' => 'x'], 'summary' => 'x']);
        SendSlackMessage::dispatchSync($again->id);
        $this->assertSame(['skipped', 'tipo desligado em Notificadores'], [$again->refresh()->status, $again->error]);
        $this->assertSame(1, $this->sentTo('hooks.slack.com'));
    }

    public function test_resend_goes_only_to_the_apps_that_receive_news(): void
    {
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user, ['paused_at' => now()]);
        $discord = $this->discordChannel($user, ['muted_types' => ['news']]);
        $match = $this->nginxNews();

        $this->actingAs($user)->get(route('alerts.news'))->assertDontSee(route('alerts.resend', $match), false);
        $this->actingAs($user)->post(route('alerts.resend', $match))
            ->assertSessionHas('error', 'O envio para o Slack está pausado. Retome em Notificadores para reenviar.');

        $slack->update(['paused_at' => null, 'muted_types' => ['news']]);
        $this->actingAs($user->fresh())->post(route('alerts.resend', $match))
            ->assertSessionHas('error', 'Nenhum app recebe notícias. Ligue as notícias em um deles em Notificadores para reenviar.');
        Http::assertNothingSent();

        $discord->update(['muted_types' => []]);
        $this->actingAs($user->fresh())->post(route('alerts.resend', $match))->assertSessionHas('status', 'Alerta reenviado para o Discord.');
        $this->assertSame(1, $this->sentTo('discord.com'));
        $this->assertSame(0, $this->sentTo('hooks.slack.com'));
    }

    public function test_digest_skips_paused_apps_and_leaves_out_the_news_when_they_are_off(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00'); // 06:00 em São Paulo
        $user = $this->clientWithProfile();
        $slack = $this->slackChannel($user, ['realtime' => false, 'paused_at' => now()]);
        $this->nginxNews();

        Carbon::setTestNow('2026-10-20 10:02:00'); // 07:02
        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('0 resumo');

        // Sem notícias e sem Radar vigiando nada: nem resumo.
        $slack->update(['paused_at' => null, 'muted_types' => ['news']]);
        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('0 resumo');

        $data = app(DigestBuilder::class)->build($slack->refresh(), now()->timezone('America/Sao_Paulo'));
        $this->assertFalse($data['news']);
        $this->assertSame([0, []], [$data['total'], $data['mine']]);
        $text = json_encode(app(SlackMessages::class)->digest($data), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Afeta você', $text);
        $this->assertStringContainsString('Nada novo no Radar de domínios', $text);

        $slack->update(['muted_types' => []]);
        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('1 resumo');
        $this->assertSame(1, NotificationDelivery::query()->where('kind', 'digest')->count());
    }

    public function test_test_message_says_what_the_app_receives(): void
    {
        $user = $this->clientWithProfile();
        $this->slackChannel($user, ['muted_types' => ['radar', 'github'], 'paused_at' => now()]);

        $this->actingAs($user)->post(route('alerts.slack.test'))->assertRedirect();

        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Recebe: só notícias (envio pausado)'));
    }
}
