<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Models\AlertMatch;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Alerts\ProfileIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Discord: conectar (servidor, mensagem direta ou webhook), entregar junto com o Slack e desconectar. */
class DiscordIntegrationTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private const GUILD = '111111111111111111';

    private const ALERTS = '222222222222222222';

    private const GENERAL = '333333333333333333';

    private const USER = '444444444444444444';

    private const DM = '555555555555555555';

    private const HOME = '999000000000000001';

    private const HOOK = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyzABCDEFGHIJ_-12';

    private const API = 'https://discord.com/api/v10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function configure(): void
    {
        config([
            'secmon.alerts.discord.client_id' => '1562116459658874941',
            'secmon.alerts.discord.client_secret' => 'segredo',
            'secmon.alerts.discord.bot_token' => 'token-do-bot',
        ]);
    }

    /** Respostas do Discord para o fluxo inteiro; `$extra` sobrescreve rotas específicas. */
    private function fakeDiscord(array $extra = []): void
    {
        Http::fake($extra + [
            'discord.com/api/v10/oauth2/token/revoke' => Http::response([]),
            'discord.com/api/v10/oauth2/token' => Http::response([
                'access_token' => 'token-da-conta',
                'token_type' => 'Bearer',
                'guild' => ['id' => self::GUILD, 'name' => 'Acme Segurança'],
            ]),
            'discord.com/api/v10/users/@me' => Http::response(['id' => self::USER, 'username' => 'romulo', 'global_name' => 'Rômulo']),
            'discord.com/api/v10/users/@me/channels' => Http::response(['id' => self::DM, 'type' => 1]),
            'discord.com/api/v10/guilds/*/members/*' => Http::response(['user' => ['id' => self::USER]], 201),
            'discord.com/api/v10/guilds/*/channels' => Http::response([
                ['id' => self::GENERAL, 'name' => 'geral', 'type' => 0, 'position' => 0],
                ['id' => '666666666666666666', 'name' => 'Voz', 'type' => 2, 'position' => 1],
                ['id' => self::ALERTS, 'name' => 'alertas', 'type' => 0, 'position' => 2],
            ]),
            'discord.com/api/v10/channels/*/messages' => Http::response(['id' => '777777777777777777']),
            'discord.com/api/v10/users/@me/guilds/*' => Http::response(null, 204),
            'discord.com/api/webhooks/*' => Http::response(['id' => '888888888888888888']),
            'hooks.slack.com/*' => Http::response('ok'),
        ]);
    }

    private function startFlow(string $mode = 'canal'): string
    {
        $redirect = $this->get(route('alerts.discord.connect', $mode))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function discordChannel(User $user, array $attributes = []): NotificationChannel
    {
        return NotificationChannel::query()->create($attributes + [
            'user_id' => $user->id,
            'type' => 'discord',
            'method' => 'channel',
            'discord_guild_id' => self::GUILD,
            'discord_guild_name' => 'Acme Segurança',
            'discord_channel_id' => self::ALERTS,
            'discord_channel_name' => 'alertas',
            'status' => 'active',
            'realtime' => true,
            'digest' => true,
            'digest_time' => '07:00',
            'digest_days' => [1, 2, 3, 4, 5, 6, 7],
            'timezone' => 'America/Sao_Paulo',
        ]);
    }

    private function sentToDiscord(string $target): \Closure
    {
        return fn (Request $r) => $r->method() === 'POST' && $r->url() === self::API."/channels/{$target}/messages";
    }

    /** Cliente com perfil (nginx) e os canais pedidos; devolve o alerta de um caso novo. */
    private function alertFor(User $user, string $title = 'Nginx RCE', string $criticity = 'critical'): AlertMatch
    {
        $case = $this->newsCase($title, $criticity, ['affected_products' => ['nginx 1.24'], 'cves' => ['CVE-2026-1']], ['title_pt' => $title]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        return AlertMatch::query()->where('case_id', $case->id)->sole();
    }

    public function test_without_credentials_only_the_webhook_is_offered(): void
    {
        $this->actingAs($this->client());

        $this->get(route('alerts.discord'))
            ->assertOk()
            ->assertSee('Indisponível: o app do Security Monitor no Discord ainda não foi configurado')
            ->assertSee(route('alerts.discord.webhook'));
        $this->get(route('alerts.discord.connect', 'canal'))->assertNotFound();
        $this->get(route('alerts.discord.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();
    }

    public function test_connect_asks_discord_only_for_what_is_needed(): void
    {
        $this->configure();
        $this->actingAs($this->client());

        $this->get(route('alerts.discord'))
            ->assertSee(route('alerts.discord.connect', 'canal'))
            ->assertSee(route('alerts.discord.connect', 'dm'));

        $location = $this->get(route('alerts.discord.connect', 'canal'))->headers->get('Location');
        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(
            ['1562116459658874941', 'bot identify', '19456', 'code', route('alerts.discord.callback')],
            [$query['client_id'], $query['scope'], $query['permissions'], $query['response_type'], $query['redirect_uri']],
        );
        $this->assertSame(40, strlen($query['state']));
        $this->assertSame(['state' => $query['state'], 'mode' => 'channel'], session('discord_oauth'));

        parse_str(parse_url($this->get(route('alerts.discord.connect', 'dm'))->headers->get('Location'), PHP_URL_QUERY), $dm);
        $this->assertSame('identify', $dm['scope']);
        $this->assertArrayNotHasKey('permissions', $dm);

        // Entrar na comunidade só existe com o servidor do Security Monitor configurado, e só aí pede guilds.join.
        $this->get(route('alerts.discord.connect', 'comunidade'))->assertNotFound();
        config(['secmon.alerts.discord.home_guild_id' => self::HOME]);
        parse_str(parse_url($this->get(route('alerts.discord.connect', 'comunidade'))->headers->get('Location'), PHP_URL_QUERY), $join);
        $this->assertSame('identify guilds.join', $join['scope']);
        parse_str(parse_url($this->get(route('alerts.discord.connect', 'dm'))->headers->get('Location'), PHP_URL_QUERY), $dm);
        $this->assertSame('identify', $dm['scope']);
    }

    public function test_server_install_then_channel_choice_sends_a_test(): void
    {
        $this->configure();
        $this->fakeDiscord();
        $user = $this->client();
        $this->actingAs($user);

        $this->get(route('alerts.discord.callback', ['code' => 'abc', 'state' => $this->startFlow(), 'guild_id' => self::GUILD]))
            ->assertRedirect(route('alerts.discord'))
            ->assertSessionHas('status', 'Bot do Security Monitor adicionado a Acme Segurança. Agora escolha o canal onde os alertas vão chegar.');

        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/oauth2/token'
            && $r['code'] === 'abc' && $r['redirect_uri'] === route('alerts.discord.callback') && $r['grant_type'] === 'authorization_code');
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/oauth2/token/revoke' && $r['token'] === 'token-da-conta');

        $channel = $user->discordChannel()->firstOrFail();
        $this->assertSame(['channel', 'pending', self::GUILD, 'Acme Segurança', self::USER], [
            $channel->method, $channel->status, $channel->discord_guild_id, $channel->discord_guild_name, $channel->discord_user_id,
        ]);

        // Só canais de texto deste servidor aparecem e podem ser escolhidos.
        $this->get(route('alerts.discord'))->assertSeeInOrder(['Em qual canal de Acme Segurança', 'geral', 'alertas'])->assertDontSee('Voz');
        $this->post(route('alerts.discord.channel'), ['channel_id' => '999999999999999999'])->assertSessionHasErrors('channel_id');
        $this->assertSame('pending', $channel->fresh()->status);

        $this->post(route('alerts.discord.channel'), ['channel_id' => self::ALERTS])
            ->assertSessionHas('status', 'Canal #alertas escolhido. Mandamos uma mensagem de teste para ele.');

        $channel->refresh();
        $this->assertSame(['active', self::ALERTS, 'alertas'], [$channel->status, $channel->discord_channel_id, $channel->discord_channel_name]);
        Http::assertSent(fn (Request $r) => $this->sentToDiscord(self::ALERTS)($r)
            && $r->header('Authorization') === ['Bot token-do-bot']
            && $r['content'] === '**Teste do Security Monitor** ✅'
            && $r['allowed_mentions'] === ['parse' => []]);
        $this->assertSame('sent', NotificationDelivery::query()->where('kind', 'test')->value('status'));
    }

    public function test_direct_message_with_a_shared_server_connects_right_away(): void
    {
        $this->configure();
        config(['secmon.alerts.discord.home_guild_id' => self::HOME]);
        $this->fakeDiscord();
        $user = $this->client();
        $this->actingAs($user);

        $this->get(route('alerts.discord.callback', ['code' => 'abc', 'state' => $this->startFlow('dm')]))
            ->assertSessionHas('status', 'Mensagem direta conectada. Mandamos um teste para você no Discord.');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/users/@me/channels' && $r['recipient_id'] === self::USER);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/oauth2/token/revoke');
        $this->assertSame(['test', 'sent'], [NotificationDelivery::query()->value('kind'), NotificationDelivery::query()->value('status')]);

        $channel = $user->discordChannel()->firstOrFail();
        $this->assertSame(['dm', 'active', self::DM, self::USER, 'Rômulo', null], [
            $channel->method, $channel->status, $channel->discord_channel_id, $channel->discord_user_id, $channel->discord_username, $channel->discord_guild_id,
        ]);
        $this->assertSame('mensagem direta para Rômulo', $channel->label());
        Http::assertSent(fn (Request $r) => $this->sentToDiscord(self::DM)($r) && str_contains($r['embeds'][0]['description'], 'Esta conversa está conectada'));
    }

    public function test_direct_message_refused_by_discord_explains_why(): void
    {
        $this->configure();
        $this->fakeDiscord(['discord.com/api/v10/users/@me/channels' => Http::response(['code' => 50007, 'message' => 'Cannot send messages to this user'], 403)]);
        $user = $this->client();
        $this->actingAs($user);

        $this->get(route('alerts.discord.callback', ['code' => 'abc', 'state' => $this->startFlow('dm')]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'não deixou o bot mandar mensagem direta'));

        $this->assertNull($user->discordChannel()->first());
    }

    public function test_without_a_shared_server_the_secmon_community_is_offered(): void
    {
        $this->configure();
        config(['secmon.alerts.discord.home_guild_id' => self::HOME]);
        $this->fakeDiscord(['discord.com/api/v10/channels/*/messages' => Http::sequence()
            ->push(['code' => 50278, 'message' => 'Cannot send messages to this user due to having no mutual guilds'], 403)
            ->push(['id' => '777777777777777777'])]);
        $user = $this->client();
        $this->actingAs($user);

        // 1ª tentativa: sem servidor em comum, nada é salvo e a tela oferece a comunidade.
        $this->get(route('alerts.discord.callback', ['code' => 'abc', 'state' => $this->startFlow('dm')]))
            ->assertRedirect(route('alerts.discord'))
            ->assertSessionHas('discord_join_offer', true);
        $this->assertNull($user->discordChannel()->first());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');

        $this->withSession(['discord_join_offer' => true])->get(route('alerts.discord'))
            ->assertSee('Falta um servidor em comum')
            ->assertSee(route('alerts.discord.connect', 'comunidade'));

        // Aceitando: entra na comunidade com o token da conta e a DM passa.
        $this->get(route('alerts.discord.callback', ['code' => 'def', 'state' => $this->startFlow('comunidade')]))
            ->assertSessionHas('status', 'Mensagem direta conectada. Mandamos um teste para você no Discord.');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::API.'/guilds/'.self::HOME.'/members/'.self::USER
            && $r['access_token'] === 'token-da-conta' && $r->header('Authorization') === ['Bot token-do-bot']);
        $this->assertSame(['dm', 'active'], [$user->discordChannel()->value('method'), $user->discordChannel()->value('status')]);
    }

    public function test_failure_to_join_the_community_is_explained(): void
    {
        $this->configure();
        config(['secmon.alerts.discord.home_guild_id' => self::HOME]);
        $this->fakeDiscord(['discord.com/api/v10/guilds/*/members/*' => Http::response(['code' => 50013, 'message' => 'Missing Permissions'], 403)]);
        $user = $this->client();
        $this->actingAs($user);

        $this->get(route('alerts.discord.callback', ['code' => 'abc', 'state' => $this->startFlow('comunidade')]))
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Não conseguimos colocar você na comunidade do Security Monitor.'));

        $this->assertNull($user->discordChannel()->first());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages'));
    }

    public function test_direct_message_without_a_shared_server_is_not_saved(): void
    {
        $this->configure();
        $this->fakeDiscord(['discord.com/api/v10/channels/*/messages' => Http::response(['code' => 50278, 'message' => 'Cannot send messages to this user due to having no mutual guilds'], 403)]);
        $user = $this->client();
        $this->actingAs($user);

        $this->get(route('alerts.discord.callback', ['code' => 'abc', 'state' => $this->startFlow('dm')]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'vocês não têm nenhum em comum'));

        $this->assertNull($user->discordChannel()->first());
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    public function test_forged_state_cancel_and_missing_server_are_refused(): void
    {
        $this->configure();
        $this->fakeDiscord(['discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'x', 'token_type' => 'Bearer'])]);
        $user = $this->client();
        $this->actingAs($user);

        $this->startFlow();
        $this->get(route('alerts.discord.callback', ['code' => 'abc', 'state' => str_repeat('x', 40)]))
            ->assertSessionHas('error', 'A conexão com o Discord expirou ou não começou aqui. Tente de novo.');

        $this->get(route('alerts.discord.callback', ['error' => 'access_denied', 'state' => $this->startFlow()]))
            ->assertSessionHas('error', 'Conexão cancelada no Discord.');

        // Sem o servidor na resposta do token (o guild_id da URL não vale: dá para forjar).
        $this->get(route('alerts.discord.callback', ['code' => 'abc', 'state' => $this->startFlow(), 'guild_id' => self::GUILD]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'não informou o servidor'));

        $this->assertNull($user->discordChannel()->first());
    }

    public function test_webhook_is_validated_and_tested(): void
    {
        $this->fakeDiscord();
        $user = $this->client();
        $this->actingAs($user);

        $this->post(route('alerts.discord.webhook'), ['webhook_url' => 'https://evil.io/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyzABCDEF'])
            ->assertSessionHasErrors('webhook_url');
        $this->assertNull($user->discordChannel()->first());

        $this->post(route('alerts.discord.webhook'), ['webhook_url' => self::HOOK])
            ->assertSessionHas('status', 'Webhook salvo. Mandamos uma mensagem de teste para o canal.');

        $channel = $user->discordChannel()->firstOrFail();
        $this->assertSame(['webhook', 'active'], [$channel->method, $channel->status]);
        Http::assertSent(fn (Request $r) => $r->url() === self::HOOK.'?wait=true' && $r['content'] === '**Teste do Security Monitor** ✅');
    }

    public function test_realtime_alert_goes_to_slack_and_discord(): void
    {
        $this->configure();
        $this->fakeDiscord();
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $this->slackChannel($user);
        $this->discordChannel($user);

        $match = $this->alertFor($user);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://hooks.slack.com/'));
        Http::assertSent(function (Request $r) use ($match) {
            $embed = $r['embeds'][0] ?? [];

            return $this->sentToDiscord(self::ALERTS)($r)
                && $r['content'] === '**CRITICAL** · novo alerta do Security Monitor'
                && $embed['title'] === 'Nginx RCE'
                && $embed['url'] === route('feed.show', $match->case->uuid)
                && $embed['color'] === 0xDC2626
                && str_contains($embed['description'], 'Por que você recebeu: **Nginx')
                && collect($embed['fields'])->contains(fn ($f) => $f['name'] === 'CVE' && $f['value'] === 'CVE-2026-1')
                && str_contains(json_encode($embed['fields']), 'signature=')
                && str_contains(json_encode($embed['fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '[Relevante](')
                && str_contains(json_encode($embed['fields'], JSON_UNESCAPED_SLASHES), '/relevante?');
        });

        $this->assertSame('sent', $match->fresh()->delivery_status);
        $this->assertSame(2, NotificationDelivery::query()->where('kind', 'realtime')->where('status', 'sent')->count());
        $this->actingAs($user)->get(route('alerts.news'))->assertSee('no Slack e no Discord, na hora');
    }

    public function test_discord_failure_breaks_only_discord(): void
    {
        $this->configure();
        $this->fakeDiscord(['discord.com/api/v10/channels/*/messages' => Http::response(['code' => 10003, 'message' => 'Unknown Channel'], 404)]);
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $slack = $this->slackChannel($user);
        $discord = $this->discordChannel($user);

        $match = $this->alertFor($user);

        $this->assertSame('sent', $match->fresh()->delivery_status);
        $this->assertSame(['broken', 'O canal não existe mais no Discord.'], [$discord->fresh()->status, $discord->fresh()->last_error]);
        $this->assertSame('active', $slack->fresh()->status);

        $this->actingAs($user)->get(route('alerts.news'))
            ->assertSee('O Discord recusou o último envio.')
            ->assertSee('Os outros canais continuam recebendo.');
    }

    public function test_quiet_channel_gets_the_case_only_in_the_digest(): void
    {
        $this->configure();
        $this->fakeDiscord();
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00', 'America/Sao_Paulo'));
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $slack = $this->slackChannel($user);
        $this->discordChannel($user, ['quiet_start' => '22:00', 'quiet_end' => '07:00']);

        $first = $this->alertFor($user, 'Nginx RCE', 'high');
        $this->assertSame('sent', $first->fresh()->delivery_status);
        Http::assertNotSent($this->sentToDiscord(self::ALERTS));

        // Os dois em silêncio: segura até o fim do silêncio que acaba primeiro.
        $slack->update(['quiet_start' => '23:00', 'quiet_end' => '06:00']);
        $second = $this->alertFor($user->fresh(), 'Nginx DoS', 'high')->fresh();
        $this->assertSame(['held', 'quiet_hours'], [$second->delivery_status, $second->delivery_note]);
        $this->assertTrue($second->release_at->eq(Carbon::parse('2026-10-20 06:00', 'America/Sao_Paulo')));
    }

    public function test_pending_discord_receives_nothing(): void
    {
        $this->configure();
        $this->fakeDiscord();
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $this->discordChannel($user, ['status' => 'pending', 'discord_channel_id' => null, 'discord_channel_name' => null]);

        $match = $this->alertFor($user);

        $this->assertSame(['skipped', 'no_channel'], [$match->delivery_status, $match->delivery_note]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages'));
        $this->actingAs($user)->get(route('alerts.news'))->assertSee('Falta escolher o canal do Discord.');
    }

    public function test_digest_reaches_discord_as_embed(): void
    {
        $this->configure();
        $this->fakeDiscord();
        // Antes das 07:00: o caso de agora cai na janela do resumo de hoje.
        Carbon::setTestNow(Carbon::parse('2026-10-20 06:30', 'America/Sao_Paulo'));
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $channel = $this->discordChannel($user, ['realtime' => false]);
        $this->alertFor($user);

        $this->artisan('secmon:alerts-digest', ['--force' => true, '--channel' => $channel->id])->assertSuccessful();

        Http::assertSent(fn (Request $r) => $this->sentToDiscord(self::ALERTS)($r)
            && str_starts_with($r['content'], '**Resumo do Security Monitor**')
            && str_starts_with($r['embeds'][0]['title'], 'Resumo das últimas 24 h')
            && str_contains($r['embeds'][0]['description'], '**Afeta você (1)**')
            && str_contains(collect($r['embeds'])->last()['description'], route('feed.index'))
            && ! str_contains(collect($r['embeds'])->last()['description'], route('alerts.discord')));
        $this->assertSame('sent', NotificationDelivery::query()->where('kind', 'digest')->value('status'));
    }

    public function test_resend_goes_to_every_active_channel(): void
    {
        $this->configure();
        $this->fakeDiscord();
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $match = $this->alertFor($user);
        $this->slackChannel($user);
        $this->discordChannel($user);

        $this->actingAs($user)->post(route('alerts.resend', $match))
            ->assertSessionHas('status', 'Alerta reenviado para o Slack e o Discord.');

        $this->assertSame(2, NotificationDelivery::query()->where('kind', 'realtime')->count());
        $this->assertSame(['sent', 'resent'], [$match->fresh()->delivery_status, $match->fresh()->delivery_note]);
    }

    public function test_bot_leaves_the_server_when_the_last_client_disconnects(): void
    {
        $this->configure();
        $this->fakeDiscord();
        $first = $this->client();
        $second = $this->client();
        $this->discordChannel($first);
        $this->discordChannel($second);

        $this->actingAs($first)->delete(route('alerts.discord.destroy'))
            ->assertSessionHas('status', 'Discord desconectado. Os alertas continuam guardados em Alertas.');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertNull($first->discordChannel()->first());

        $this->actingAs($second)->delete(route('alerts.discord.destroy'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/users/@me/guilds/'.self::GUILD);
    }

    public function test_bot_never_leaves_the_secmon_server(): void
    {
        $this->configure();
        config(['secmon.alerts.discord.home_guild_id' => self::GUILD]);
        $this->fakeDiscord();
        $user = $this->client();
        $this->discordChannel($user);

        $this->actingAs($user)->delete(route('alerts.discord.destroy'));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_notifiers_page_shows_the_discord_state(): void
    {
        $user = $this->client();
        $this->discordChannel($user, ['status' => 'pending', 'discord_channel_id' => null, 'discord_channel_name' => null]);

        $this->actingAs($user)->get(route('alerts.notifiers'))
            ->assertSeeInOrder(['Discord', 'falta escolher o canal', 'O bot já está em Acme Segurança']);
    }
}
