<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Notifier;
use App\Services\Alerts\Telegram\TelegramClient;
use App\Services\Alerts\Telegram\UpdateHandler;
use App\Services\Alerts\Webhook\WebhookConnector;
use App\Services\Alerts\Webhook\WebhookOwnership;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E5a1 (DESENHO-5.4 §8.3): o canal da empresa recebendo o que é de outro setor, a posse do webhook pelo escopo
 * e a conversa física do Telegram compartilhada. `rt_*` = achado (falha antes da correção); `evidencia_*` = resistiu.
 */
class RedTeamE5a1Test extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
    }

    private function channel(User $by, ChannelScope $scope, string $type = 'slack', string $method = 'webhook', array $extra = []): NotificationChannel
    {
        return DB::transaction(function () use ($by, $scope, $type, $method, $extra) {
            $channel = new NotificationChannel(NotificationChannel::defaults() + $extra + ['type' => $type, 'method' => $method, 'status' => 'active',
                'webhook_url' => 'https://hooks.slack.com/services/T000/B000/'.str_repeat('b', 24)]);

            return tap($channel->forceFill(app(CompanyChannels::class)->claim($by, $scope, $type, $method)))->save();
        });
    }

    private function domain(string $name, ?Sector $sector = null): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate([
            'user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'sector_id' => $sector?->id, 'domain' => $name, 'source' => 'radar',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now(),
        ]);
    }

    /**
     * D1: o resumo diário passa por todos os canais e monta o conteúdo com o que o `user_id` do canal criou. O canal do RH
     * que a admin conectou levaria o resumo dos alertas do TI dela (e, depois da poda, o de tudo o que passou à admin).
     * Até a E5b1 dar o destino pelo escopo, canal da empresa não recebe resumo.
     */
    public function test_rt_d1_the_daily_digest_does_not_go_to_a_company_channel_by_who_connected_it(): void
    {
        Settings::set('alerts.enabled', true);
        Settings::set('alerts.digest_enabled', true);
        $this->profileOf($this->ana, $this->acme, $this->ti);
        $rh = $this->channel($this->ana, ChannelScope::sector($this->acme->id, $this->rh->id));
        $rh->forceFill(['digest' => true, 'digest_days' => [1, 2, 3, 4, 5, 6, 7]])->save();

        $this->mock(Notifier::class, fn ($mock) => $mock->shouldReceive('digest')->never());
        $this->artisan('secmon:alerts-digest', ['--force' => true, '--channel' => $rh->id])->assertSuccessful();
    }

    // O resumo da conta pessoal continua igual (o guard é só para o canal da empresa).
    public function test_evidencia_d1_the_personal_digest_still_goes_out(): void
    {
        Settings::set('alerts.enabled', true);
        Settings::set('alerts.digest_enabled', true);
        $bia = User::factory()->create();
        $this->profileOf($bia);
        $mine = NotificationChannel::forUser($bia->id, 'slack');
        $mine->forceFill(['method' => 'webhook', 'status' => 'active', 'webhook_url' => 'https://hooks.slack.com/services/T1/B1/'.str_repeat('c', 24),
            'digest' => true, 'digest_days' => [1, 2, 3, 4, 5, 6, 7]])->save();

        $this->mock(Notifier::class, fn ($mock) => $mock->shouldReceive('digest')->once()->andReturn(null));
        $this->artisan('secmon:alerts-digest', ['--force' => true, '--channel' => $mine->id])->assertSuccessful();
    }

    // A posse do webhook segue o escopo: o geral só usa domínio sem setor, o do TI não usa o do RH, e mudar o domínio de
    // setor para o envio (holds) mesmo com a cópia comprovada.
    public function test_evidencia_webhook_ownership_follows_the_scope(): void
    {
        $this->domain('acme.com.br');
        $rhOnly = $this->domain('rh-acme.com.br', $this->rh);
        $connector = app(WebhookConnector::class);
        $session = $this->app['session.store'];

        try {
            $connector->create($this->bruno, 'TI', 'https://hooks.rh-acme.com.br/x', 'nenhuma', null, $session, ChannelScope::sector($this->acme->id, $this->ti->id));
            $this->fail('O TI usou o domínio do RH.');
        } catch (ActionRefused) {
            $this->assertSame(0, NotificationChannel::query()->where('company_id', $this->acme->id)->count());
        }

        $general = $connector->create($this->ana, 'Geral', 'https://hooks.acme.com.br/g', 'nenhuma', null, $session, ChannelScope::general($this->acme->id));
        $this->assertTrue(app(WebhookOwnership::class)->holds($general->fresh()));

        OwnedDomain::query()->where('domain', 'acme.com.br')->update(['sector_id' => $this->rh->id]);
        $this->assertFalse(app(WebhookOwnership::class)->holds($general->fresh()), 'domínio foi para o RH: o geral para');
        $this->assertNotNull($rhOnly->id);
    }

    // Membro e responsável de outro setor não criam webhook da empresa; a conta de empresa sem escopo também não.
    public function test_evidencia_who_creates_company_webhooks(): void
    {
        $this->domain('acme.com.br');
        $caio = $this->companyAccount($this->acme);
        $this->joinSector($caio, $this->ti);
        $connector = app(WebhookConnector::class);
        $session = $this->app['session.store'];
        $tries = [
            [$caio, ChannelScope::sector($this->acme->id, $this->ti->id)],
            [$caio, null],
            [$this->bruno, ChannelScope::general($this->acme->id)],
            [$this->bruno, ChannelScope::sector($this->acme->id, $this->rh->id)],
        ];

        foreach ($tries as $i => [$who, $scope]) {
            try {
                $connector->create($who, "W{$i}", "https://hooks.acme.com.br/{$i}", 'nenhuma', null, $session, $scope);
                $this->fail("Criou o webhook {$i}.");
            } catch (ActionRefused) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    /**
     * A decisão do UpdateHandler::channels($chatId) sem filtro: a mesma conversa física serve à conta pessoal e ao
     * canal da empresa. A migração para supergrupo e a saída do bot valem para os dois, e o bot não sai do grupo
     * que a empresa ainda usa quando a pessoa desconecta o dela. Nada passa de um canal para o outro.
     */
    public function test_evidencia_a_shared_telegram_group_moves_together_and_the_bot_stays_for_the_company(): void
    {
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.username' => 'SecurityMonitorTestBot']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
        $company = $this->channel($this->ana, ChannelScope::general($this->acme->id), 'telegram', 'group', ['telegram_chat_id' => '-4001', 'webhook_url' => null]);
        $bia = User::factory()->create();
        $personal = NotificationChannel::forUser($bia->id, 'telegram');
        $personal->forceFill(['method' => 'group', 'telegram_chat_id' => '-4001', 'status' => 'active'])->save();

        $handler = app(UpdateHandler::class);
        $handler->handle(['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => -4001, 'type' => 'group'], 'migrate_to_chat_id' => -1004001]]);
        $this->assertSame(['-1004001', '-1004001'], [$company->fresh()->telegram_chat_id, $personal->fresh()->telegram_chat_id]);
        $this->assertSame([$this->acme->id, null], [$company->fresh()->company_id, $personal->fresh()->company_id]);

        $personal->delete();
        app(TelegramClient::class)->leaveIfUnused('-1004001');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'leaveChat'));

        $handler->handle(['update_id' => 2, 'my_chat_member' => ['chat' => ['id' => -1004001, 'type' => 'supergroup'], 'new_chat_member' => ['status' => 'kicked']]]);
        $this->assertSame('broken', $company->fresh()->status);
    }
}
