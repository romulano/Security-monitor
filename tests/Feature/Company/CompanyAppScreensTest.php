<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E5a2 (DESENHO-5.4 §2.4): as telas do Slack, do Discord e do Telegram na conta de empresa — `?canal=` abre um canal
 * que a conta configura, `?setor=` conecta um novo onde ela configura, sem os dois é 404 (nada de "o primeiro") —, sem
 * a conversa privada (P1) e o teste D das rotas (o membro, outro setor e outra empresa: 404).
 */
class CompanyAppScreensTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $bruno;

    private User $caio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
        config([
            'secmon.alerts.slack.client_id' => '123.456', 'secmon.alerts.slack.client_secret' => 'segredo',
            'secmon.alerts.discord.client_id' => '1562116459658874941', 'secmon.alerts.discord.client_secret' => 'segredo', 'secmon.alerts.discord.bot_token' => 'token-do-bot',
            'secmon.alerts.telegram.bot_token' => '123456:ABC', 'secmon.alerts.telegram.bot_username' => 'SecurityMonitorDevBot',
        ]);
        Http::fake(['hooks.slack.com/*' => Http::response('ok'), 'discord.com/*' => Http::response([], 204)]);
    }

    private function channel(User $by, ChannelScope $scope, string $type = 'slack'): NotificationChannel
    {
        return DB::transaction(function () use ($by, $scope, $type) {
            $channel = new NotificationChannel(NotificationChannel::defaults() + ['type' => $type, 'method' => $type === 'telegram' ? 'group' : 'webhook', 'status' => 'active',
                'telegram_chat_id' => $type === 'telegram' ? '-100123' : null, 'webhook_url' => 'https://hooks.slack.com/services/T000/B000/'.str_repeat('a', 24)]);

            return tap($channel->forceFill(app(CompanyChannels::class)->claim($by, $scope, $type, $channel->method)))->save();
        });
    }

    private function ti(): ChannelScope
    {
        return ChannelScope::sector($this->acme->id, $this->ti->id);
    }

    public function test_the_company_account_says_which_channel_or_where_to_connect(): void
    {
        foreach (['slack', 'discord', 'telegram'] as $app) {
            // Sem ?canal= nem ?setor=, nos gerais (responsável), no RH, ou como membro: 404.
            foreach ([[$this->bruno, []], [$this->bruno, ['setor' => 'geral']], [$this->bruno, ['setor' => $this->rh->id]], [$this->caio, ['setor' => $this->ti->id]]] as [$who, $query]) {
                $this->refreshVisibility();
                $this->actingAs($who)->get(route('alerts.'.$app, $query))->assertNotFound();
            }

            $this->refreshVisibility();
            $this->actingAs($this->bruno)->get(route('alerts.'.$app, ['setor' => $this->ti->id]))->assertOk()->assertSee('Este canal é do setor TI: recebe só os avisos do que é do setor TI.');
        }

        // Canal da empresa não é conversa privada (P1): o Discord sem a mensagem direta, o Telegram sem a conversa privada.
        $this->refreshVisibility();
        $this->actingAs($this->bruno)->get(route('alerts.discord', ['setor' => $this->ti->id]))->assertDontSee('Em mensagem direta')->assertSee('a mensagem direta com o bot não vale para a empresa');
        $this->refreshVisibility();
        $this->actingAs($this->bruno)->get(route('alerts.telegram', ['setor' => $this->ti->id]))->assertDontSee('Numa conversa privada')->assertSee('Use um grupo do Telegram')->assertDontSee('Discord: a mensagem')
            ->assertSee('action="'.route('alerts.telegram.connect', ['setor' => $this->ti->id]).'"', false);

        // A conta pessoal: a tela de sempre, sem parâmetro.
        $this->actingAs(User::factory()->create())->get(route('alerts.slack'))->assertOk()->assertDontSee('Este canal é');
    }

    public function test_route_map_d_every_app_route_with_another_sectors_or_companys_channel_is_404(): void
    {
        [, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        // Dois canais por app no TI, para o teste: acima dos 5 padrão.
        $this->acme->forceFill(['limit_sector_channels' => 10])->save();
        $general = $this->channel($this->ana, ChannelScope::general($this->acme->id));

        foreach (['slack', 'discord', 'telegram'] as $app) {
            $mine = $this->channel($this->bruno, $this->ti(), $app);
            $routes = [['get', 'alerts.'.$app], ['put', 'alerts.'.$app.'.update'], ['delete', 'alerts.'.$app.'.destroy'], ['post', 'alerts.'.$app.'.test']];
            $other = $app === 'slack' ? $this->channel($this->bruno, $this->ti(), 'telegram') : $this->channel($this->bruno, $this->ti(), 'slack');

            // O membro do setor, a admin de outra empresa, o responsável no canal geral e o id de outro app: 404 em todas.
            foreach ([[$this->caio, $mine], [$paulo, $mine], [$this->bruno, $app === 'slack' ? $general : $other], [$this->bruno, $other]] as [$who, $channel]) {
                foreach ($routes as [$method, $name]) {
                    $this->refreshVisibility();
                    $this->actingAs($who)->{$method}(route($name, ['canal' => $channel->id]), ['digest_time' => '07:00', 'digest_scope' => 'all', 'timezone' => 'America/Sao_Paulo'])
                        ->assertNotFound();
                }
            }
            $this->assertNotNull($mine->fresh());

            $this->refreshVisibility();
            $this->actingAs($this->bruno)->get($mine->settingsUrl())->assertOk()->assertSee('Este canal é do setor TI: recebe só os avisos do que é do setor TI.')
                // As preferências falam dos alertas do setor, e desconectar diz o nome, o efeito e o perigo.
                ->assertSee('Alertas de Notícias, na hora')->assertSee('casar com um alerta de Notícias do setor TI')
                ->assertSee('Conectado por '.$this->bruno->name, false)
                ->assertSee('do setor TI? Ninguém mais recebe por ele os avisos do setor TI.', false)->assertSee('data-confirm-tone="danger"', false)
                ->assertSee('action="'.route('alerts.'.$app.'.update', ['canal' => $mine->id]).'"', false);
        }

        $this->refreshVisibility();
        $this->actingAs($this->ana)->get($general->settingsUrl())->assertOk()->assertSee('Este canal é dos canais gerais: recebe os avisos do que é da empresa toda (sem setor).')->assertSee('dos canais gerais da empresa? Ninguém mais recebe por ele os avisos da empresa toda.', false);
    }

    public function test_connecting_a_slack_webhook_in_the_sector_and_disconnecting_it(): void
    {
        $this->actingAs($this->bruno)->post(route('alerts.slack.webhook', ['setor' => $this->ti->id]), ['webhook_url' => 'https://hooks.slack.com/services/T111/B222/'.str_repeat('b', 24)])
            ->assertSessionHasNoErrors();
        $slack = NotificationChannel::query()->sole();
        $this->assertSame([$this->acme->id, $this->ti->id], [$slack->company_id, $slack->sector_id]);
        $this->assertSame(route('alerts.slack', ['canal' => $slack->id]), $slack->settingsUrl());

        // O membro não conecta nem desconecta.
        $this->refreshVisibility();
        $this->actingAs($this->caio)->post(route('alerts.slack.webhook', ['setor' => $this->ti->id]), ['webhook_url' => 'https://hooks.slack.com/services/T111/B333/'.str_repeat('c', 24)])->assertNotFound();
        $this->refreshVisibility();
        $this->actingAs($this->caio)->delete(route('alerts.slack.destroy', ['canal' => $slack->id]))->assertNotFound();

        $this->refreshVisibility();
        $this->actingAs($this->bruno)->delete(route('alerts.slack.destroy', ['canal' => $slack->id]))->assertRedirect(route('alerts.notifiers'));
        $this->assertNull($slack->fresh());
    }

    public function test_telegram_in_the_company_only_in_a_group(): void
    {
        $this->actingAs($this->bruno)->post(route('alerts.telegram.connect', ['setor' => $this->ti->id]), ['modo' => 'privado'])
            ->assertSessionHas('error', CompanyChannels::PRIVATE_REFUSED);
        $this->assertNull(session('telegram_link'), 'Nenhum link de conexão gerado.');

        $this->refreshVisibility();
        $this->actingAs($this->bruno)->post(route('alerts.telegram.connect', ['setor' => $this->ti->id]), ['modo' => 'grupo'])
            ->assertRedirect(route('alerts.telegram', ['setor' => $this->ti->id]));
    }

    public function test_notifiers_link_each_company_channel_to_its_screen_and_offer_the_apps(): void
    {
        $slack = $this->channel($this->bruno, $this->ti());

        $this->actingAs($this->bruno)->get(route('alerts.notifiers'))->assertOk()
            ->assertSee('href="'.route('alerts.slack', ['canal' => $slack->id]).'"', false)
            ->assertSee('href="'.route('alerts.telegram', ['setor' => $this->ti->id]).'"', false)
            ->assertSee('href="'.route('alerts.discord', ['setor' => $this->ti->id]).'"', false);

        // O Discord pessoal troca o canal do servidor por ?escolher=1 (o ?canal= agora é o canal da empresa).
        $bia = User::factory()->create();
        $this->actingAs($bia)->get(route('alerts.discord', ['canal' => 1]))->assertOk();
    }
}
