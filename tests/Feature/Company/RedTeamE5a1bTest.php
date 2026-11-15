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
use App\Services\Alerts\Telegram\ConnectionLinks;
use App\Services\Alerts\Telegram\TelegramConnector;
use App\Services\Company\ChannelScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E5a1b (DESENHO-5.4 §8.3): o alvo do conector que atravessa a volta do app. `rt_*` = achado (falha antes da
 * correção); `evidencia_*` = resistiu.
 */
class RedTeamE5a1bTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        config([
            'secmon.alerts.slack.client_id' => '123.456', 'secmon.alerts.slack.client_secret' => 'segredo',
            'secmon.alerts.discord.client_id' => '1562116459658874941', 'secmon.alerts.discord.client_secret' => 'segredo', 'secmon.alerts.discord.bot_token' => 'token-do-bot',
            'secmon.alerts.discord.home_guild_id' => '999999999999999999',
            'secmon.alerts.telegram.bot_token' => '123456:ABC', 'secmon.alerts.telegram.bot_username' => 'SecurityMonitorDevBot',
        ]);
    }

    /** O UpdateHandler de antes desta PR (o secmon:telegram-updates do supervisor velho, de pé no deploy) lendo o cache. */
    private function oldConsume(string $code): ?int
    {
        $userId = Cache::pull('telegram-link:'.hash('sha256', $code));

        return $userId ? (int) $userId : null;
    }

    /**
     * T1: o deploy troca o app antes do supervisor (trocar.sh, passo 5: espera o job em andamento). O link novo guardava
     * ['user' => id, 'target' => []], e o poller velho faz (int) no array = 1: a conversa de quem conectava virava o
     * Telegram da conta 1 (os alertas dela iam para o chat de outra pessoa). O link pessoal tem de continuar o id puro.
     */
    public function test_rt_t1_a_personal_link_still_reads_right_in_the_previous_version(): void
    {
        User::factory()->count(3)->create();
        $bia = User::factory()->create();
        $this->assertNotSame(1, $bia->id);

        $code = app(ConnectionLinks::class)->create($bia->id);
        $this->assertSame($bia->id, $this->oldConsume($code));
    }

    // E o link da empresa nunca é lido pela versão de antes como o de outra conta: ela não acha nada (expirou).
    public function test_rt_t1_a_company_link_is_invisible_to_the_previous_version(): void
    {
        $code = app(ConnectionLinks::class)->create($this->ana->id, ['company' => $this->acme->id, 'sector' => null]);
        $this->assertTrue(app(ConnectionLinks::class)->pending($code));
        $this->assertNull($this->oldConsume($code));
        $this->assertSame(['user' => $this->ana->id, 'target' => ['company' => $this->acme->id, 'sector' => null]], app(ConnectionLinks::class)->consume($code));
    }

    // O link pessoal da versão nova também é lido pela nova (e o de antes, só o id, também).
    public function test_evidencia_t1_links_of_both_versions_are_read(): void
    {
        $bia = User::factory()->create();
        $links = app(ConnectionLinks::class);
        $this->assertSame(['user' => $bia->id, 'target' => []], $links->consume($links->create($bia->id)));

        Cache::put('telegram-link:'.hash('sha256', $old = str_repeat('A', 32)), $bia->id, now()->addMinutes(30));
        $this->assertTrue($links->pending($old));
        $this->assertSame(['user' => $bia->id, 'target' => []], $links->consume($old));
        $this->assertNull($links->consume($old), 'uma vez só');
    }

    /**
     * T2: a conta de empresa sem escopo (o controller de hoje ainda não passa) no modo "comunidade" do Discord: a volta
     * colocava a pessoa no servidor do Security Monitor e abria a DM antes de o target() recusar.
     */
    public function test_rt_t2_discord_dm_for_a_company_account_refuses_before_touching_discord(): void
    {
        Http::fake([
            'discord.com/api/v10/oauth2/token/revoke' => Http::response([]),
            'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'token-da-conta', 'token_type' => 'Bearer']),
            'discord.com/api/v10/users/@me' => Http::response(['id' => '222222222222222222', 'username' => 'ana']),
            'discord.com/api/v10/*' => Http::response(['id' => '333333333333333333']),
        ]);
        $discord = app(DiscordConnector::class);
        $url = $discord->authorizeUrl('comunidade', $this->app['session.store']);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        try {
            $discord->finishOAuth($this->ana, $this->app['session.store'], (string) $query['state'], 'abc');
            $this->fail('A conta de empresa conectou uma DM.');
        } catch (ActionRefused) {
            $this->assertSame(0, NotificationChannel::query()->count());
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/guilds/') || str_contains($r->url(), '/users/@me/channels'));
    }

    // Sessão forjada com o alvo de outra empresa (ou de um setor dela): a volta do Slack recusa e revoga o token.
    public function test_evidencia_a_forged_slack_target_of_another_company_is_refused_and_revoked(): void
    {
        [$beta] = $this->activeCompany('Beta', 'paulo@beta.com');
        $betaSector = $this->companySector($beta, 'Vendas');
        Http::fake([
            'slack.com/api/oauth.v2.access' => Http::response(['ok' => true, 'access_token' => 'xoxb-x', 'team' => ['name' => 'Acme'],
                'incoming_webhook' => ['url' => 'https://hooks.slack.com/services/T1/B2/abcdef', 'channel' => '#alertas', 'channel_id' => 'C123']]),
            'slack.com/api/auth.revoke' => Http::response(['ok' => true]),
            'hooks.slack.com/*' => Http::response('ok'),
        ]);
        $slack = app(SlackConnector::class);

        foreach ([['company' => $beta->id, 'sector' => null], ['company' => $beta->id, 'sector' => $betaSector->id], ['company' => $this->acme->id, 'sector' => $betaSector->id]] as $target) {
            $this->app['session.store']->put('slack_oauth_state', ['state' => $state = str_repeat('s', 40), 'target' => $target]);

            try {
                $slack->finishOAuth($this->ana, $this->app['session.store'], $state, 'abc');
                $this->fail('Gravou o canal no alvo forjado.');
            } catch (ActionRefused) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, NotificationChannel::query()->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'auth.revoke'));
    }

    // O link do Telegram de outro canal (alvo existente que a conta não configura) é recusado já ao gerar.
    public function test_evidencia_telegram_link_for_a_channel_the_account_does_not_manage(): void
    {
        $bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($bruno, $this->ti, manager: true);
        $general = NotificationChannel::query()->forceCreate(NotificationChannel::defaults() + ['user_id' => $this->ana->id, 'company_id' => $this->acme->id,
            'type' => 'telegram', 'method' => 'group', 'telegram_chat_id' => '-55', 'status' => 'active']);

        try {
            app(TelegramConnector::class)->startLink($bruno, $this->app['session.store'], true, existing: $general);
            $this->fail('Gerou o link para o canal geral.');
        } catch (ActionRefused) {
            $this->assertNull($this->app['session.store']->get('telegram_link'));
        }

        try {
            app(TelegramConnector::class)->startLink($this->ana, $this->app['session.store'], false, ChannelScope::sector($this->acme->id, $this->ti->id));
            $this->fail('Gerou o link privado da empresa.');
        } catch (ActionRefused) {
            $this->addToAssertionCount(1);
        }
    }
}
