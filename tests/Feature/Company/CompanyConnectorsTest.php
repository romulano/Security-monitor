<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discord\DiscordConnector;
use App\Services\Alerts\Slack\SlackConnector;
use App\Services\Alerts\Telegram\TelegramConnector;
use App\Services\Alerts\Telegram\UpdateHandler;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E5a1b (DESENHO-5.4 §2.3): os conectores do Slack, do Discord e do Telegram gravam o canal da empresa no escopo (os
 * gerais ou um setor), pelo CompanyChannels, e nunca numa conversa privada (P1): Discord sem DM, Slack sem conversa
 * direta, Telegram só em grupo. Quem pode é conferido na ida e de novo na volta do app.
 */
class CompanyConnectorsTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private User $bruno;

    private User $caio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
        config([
            'secmon.alerts.slack.client_id' => '123.456', 'secmon.alerts.slack.client_secret' => 'segredo',
            'secmon.alerts.discord.client_id' => '1562116459658874941', 'secmon.alerts.discord.client_secret' => 'segredo', 'secmon.alerts.discord.bot_token' => 'token-do-bot',
            'secmon.alerts.telegram.bot_token' => '123456:ABC', 'secmon.alerts.telegram.bot_username' => 'SecurityMonitorDevBot',
        ]);
    }

    private function store(): Session
    {
        return $this->app['session.store'];
    }

    private function refused(callable $action): string
    {
        try {
            $action();
        } catch (ActionRefused $e) {
            return $e->getMessage();
        }

        $this->fail('Aceitou o que devia recusar.');
    }

    private function sector(): ChannelScope
    {
        return ChannelScope::sector($this->acme->id, $this->ti->id);
    }

    /** As instalações do Slack, em ordem: o id do canal escolhido na tela do Slack em cada volta. */
    private function slackInstalls(string ...$channelIds): void
    {
        $answers = Http::sequence();

        foreach ($channelIds as $id) {
            $answers->push(['ok' => true, 'access_token' => 'xoxb-empresa', 'team' => ['name' => 'Acme'],
                'incoming_webhook' => ['url' => 'https://hooks.slack.com/services/T1/B2/abcdef', 'channel' => '#alertas', 'channel_id' => $id]]);
        }

        Http::fake([
            'slack.com/api/oauth.v2.access' => $answers,
            'slack.com/api/auth.revoke' => Http::response(['ok' => true]),
            'hooks.slack.com/*' => Http::response('ok'),
        ]);
    }

    /** O state que o authorizeUrl guardou na sessão. */
    private function state(string $key): string
    {
        $started = $this->store()->get($key);

        return is_array($started) ? $started['state'] : $started;
    }

    public function test_slack_connects_a_company_channel_in_the_scope_and_never_a_direct_conversation(): void
    {
        $slack = app(SlackConnector::class);

        // A ida já confere quem pode: o membro não conecta, e a conta de empresa sempre diz onde.
        $this->assertStringStartsWith('Só quem responde pelo setor', $this->refused(fn () => $slack->authorizeUrl($this->store(), $this->caio, $this->sector())));
        $this->assertSame('Escolha onde o canal recebe: nos gerais da empresa ou num setor.', $this->refused(fn () => $slack->saveWebhook($this->bruno, 'https://hooks.slack.com/services/T1/B2/abc')));

        // Conversa direta (D…) no canal da empresa: recusa, e o token instalado é revogado.
        $this->slackInstalls('D0123456', 'C0123456', 'C0123456');
        $slack->authorizeUrl($this->store(), $this->bruno, $this->sector());
        $this->assertSame(SlackConnector::DM_REFUSED, $this->refused(fn () => $slack->finishOAuth($this->bruno, $this->store(), $this->state('slack_oauth_state'), 'abc')));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://slack.com/api/auth.revoke' && $r['token'] === 'xoxb-empresa');
        $this->assertSame(0, NotificationChannel::query()->count());

        // Um canal do espaço de trabalho: nasce no setor, de quem conectou, fora de "meus canais", e entra na Atividade.
        $slack->authorizeUrl($this->store(), $this->bruno, $this->sector());
        $channel = $slack->finishOAuth($this->bruno, $this->store(), $this->state('slack_oauth_state'), 'abc');
        $this->assertSame([$this->acme->id, $this->ti->id, $this->bruno->id, 'oauth'], [$channel->company_id, $channel->sector_id, $channel->user_id, $channel->method]);
        $this->assertNull($this->bruno->slackChannel()->first());
        $this->assertTrue(DB::table('company_audit')->where('action', 'channel.added')->where('target_label', 'Slack')->exists());

        // Perdeu o papel entre a ida e a volta: a volta confere de novo.
        $slack->authorizeUrl($this->store(), $this->bruno, $this->sector());
        DB::table('sector_user')->where('user_id', $this->bruno->id)->update(['is_manager' => false]);
        $this->refreshVisibility();
        $this->assertStringStartsWith('Só quem responde pelo setor', $this->refused(fn () => $slack->finishOAuth($this->bruno, $this->store(), $this->state('slack_oauth_state'), 'abc')));
        $this->assertSame(1, NotificationChannel::query()->count());
    }

    public function test_discord_in_the_company_is_only_the_server_never_a_direct_message(): void
    {
        $discord = app(DiscordConnector::class);

        foreach (['dm', 'comunidade'] as $mode) {
            $this->assertSame(CompanyChannels::PRIVATE_REFUSED, $this->refused(fn () => $discord->authorizeUrl($mode, $this->store(), $this->ana, ChannelScope::general($this->acme->id))));
        }

        // Mesmo com a sessão forjada (modo dm e alvo da empresa), a volta recusa antes de abrir a DM.
        Http::fake([
            'discord.com/api/v10/oauth2/token/revoke' => Http::response([]),
            'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'token-da-conta', 'token_type' => 'Bearer', 'guild' => ['id' => '111111111111111111', 'name' => 'Acme']]),
            'discord.com/api/v10/users/@me' => Http::response(['id' => '222222222222222222', 'username' => 'ana']),
            'discord.com/api/v10/*' => Http::response(['id' => '333333333333333333']),
        ]);
        $this->store()->put('discord_oauth', ['state' => str_repeat('s', 40), 'mode' => 'dm', 'target' => ['company' => $this->acme->id, 'sector' => null]]);
        $this->assertSame(CompanyChannels::PRIVATE_REFUSED, $this->refused(fn () => $discord->finishOAuth($this->ana, $this->store(), str_repeat('s', 40), 'abc')));
        $this->assertSame(0, NotificationChannel::query()->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/users/@me/channels'));

        // O servidor: nasce geral, pendente até escolher o canal do servidor.
        $discord->authorizeUrl('canal', $this->store(), $this->ana, ChannelScope::general($this->acme->id));
        $channel = $discord->finishOAuth($this->ana, $this->store(), $this->state('discord_oauth'), 'abc');
        $this->assertSame([$this->acme->id, null, 'channel', 'pending', '111111111111111111'], [$channel->company_id, $channel->sector_id, $channel->method, $channel->status, $channel->discord_guild_id]);

        // O selectChannel só serve ao canal do bot num servidor.
        $channel->forceFill(['method' => 'webhook', 'discord_guild_id' => null])->save();
        $this->assertStringStartsWith('Este Discord não está conectado a um servidor', $this->refused(fn () => $discord->selectChannel($channel, '444444444444444444')));
    }

    public function test_telegram_in_the_company_is_only_a_group_and_the_start_checks_who_again(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $telegram = app(TelegramConnector::class);
        $handler = app(UpdateHandler::class);
        $start = fn (string $code, array $chat) => $handler->handle(['update_id' => random_int(1, 1_000_000), 'message' => [
            'message_id' => 10, 'chat' => $chat, 'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Bruno'], 'text' => '/start '.$code,
        ]]);
        $group = ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'TI Alertas'];
        $private = ['id' => 555, 'type' => 'private', 'first_name' => 'Bruno'];

        // O link "numa conversa privada" nem sai no escopo da empresa, e o membro não gera link.
        $this->assertSame(CompanyChannels::PRIVATE_REFUSED, $this->refused(fn () => $telegram->startLink($this->bruno, $this->store(), false, $this->sector())));
        $this->assertStringStartsWith('Só quem responde pelo setor', $this->refused(fn () => $telegram->startLink($this->caio, $this->store(), true, $this->sector())));

        // O link do grupo usado numa conversa privada: o bot responde e nada é gravado.
        $telegram->startLink($this->bruno, $this->store(), true, $this->sector());
        $start($this->store()->get('telegram_link')['code'], $private);
        $this->assertSame(0, NotificationChannel::query()->count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '555' && str_contains((string) $r['text'], 'só funciona num grupo'));

        // No grupo: o canal do setor TI, com o id negativo do grupo.
        $telegram->startLink($this->bruno, $this->store(), true, $this->sector());
        $start($this->store()->get('telegram_link')['code'], $group);
        $channel = NotificationChannel::query()->sole();
        $this->assertSame([$this->acme->id, $this->ti->id, 'group', '-1001234567890'], [$channel->company_id, $channel->sector_id, $channel->method, $channel->telegram_chat_id]);
        $this->assertNull($this->bruno->telegramChannel()->first());

        // Perdeu o papel nos 30 minutos do link: o /start recusa no chat.
        $telegram->startLink($this->bruno, $this->store(), true, $this->sector());
        DB::table('sector_user')->where('user_id', $this->bruno->id)->update(['is_manager' => false]);
        $this->refreshVisibility();
        $start($this->store()->get('telegram_link')['code'], ['id' => -1009999, 'type' => 'group', 'title' => 'Outro']);
        $this->assertSame(1, NotificationChannel::query()->count());
    }

    // O fluxo pessoal não acha nem sobrescreve o canal da empresa conectado pela mesma pessoa (Red Team da E5a0).
    public function test_the_personal_flow_never_touches_a_company_channel(): void
    {
        $slack = app(SlackConnector::class);
        $company = $slack->saveWebhook($this->ana, 'https://hooks.slack.com/services/T1/B2/empresa', ChannelScope::general($this->acme->id));

        // A admin é conta de empresa: sem escopo, não há canal pessoal para gravar.
        $this->assertSame('Escolha onde o canal recebe: nos gerais da empresa ou num setor.', $this->refused(fn () => $slack->saveWebhook($this->ana, 'https://hooks.slack.com/services/T1/B2/outro')));
        $this->assertSame('https://hooks.slack.com/services/T1/B2/empresa', $company->fresh()->webhook_url);

        // Editar um canal da empresa existente é de quem configura o escopo.
        $this->assertSame('Este canal não está entre os que você configura.', $this->refused(fn () => $slack->saveWebhook($this->bruno, 'https://hooks.slack.com/services/T1/B2/x', existing: $company)));
        $slack->saveWebhook($this->ana, 'https://hooks.slack.com/services/T1/B2/novo', existing: $company);
        $this->assertSame('https://hooks.slack.com/services/T1/B2/novo', $company->fresh()->webhook_url);
        $this->assertSame(1, NotificationChannel::query()->count());
    }
}
