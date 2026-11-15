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

/** Red Team da E5a2a/E5a2b (DESENHO-5.4 §8.3): as telas dos canais. Só evidências (nada a corrigir). */
class RedTeamE5a2Test extends TestCase
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
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
        foreach ([$this->ana, $this->bruno, $this->caio] as $user) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    private function channel(User $by, ChannelScope $scope, string $type = 'slack', array $extra = []): NotificationChannel
    {
        return DB::transaction(function () use ($by, $scope, $type, $extra) {
            $channel = new NotificationChannel(NotificationChannel::defaults() + $extra + ['type' => $type, 'method' => 'webhook', 'status' => 'active',
                'webhook_url' => 'https://hooks.slack.com/services/T0SEGREDO/B0SEGREDO/'.str_repeat('z', 20).'fim9']);

            return tap($channel->forceFill(app(CompanyChannels::class)->claim($by, $scope, $type, $channel->method)))->save();
        });
    }

    // O membro vê onde chega o canal do setor, nunca a URL nem o segredo do webhook.
    public function test_evidencia_who_only_sees_never_gets_the_url_or_the_secret(): void
    {
        $this->channel($this->bruno, ChannelScope::sector($this->acme->id, $this->ti->id));
        $this->channel($this->ana, ChannelScope::sector($this->acme->id, $this->ti->id), 'webhook', [
            'method' => 'json', 'name' => 'Prod', 'webhook_url' => 'https://hooks.acme.com.br/caminho-secreto-123', 'webhook_host' => 'hooks.acme.com.br',
            'webhook_secret' => 'whsec-nao-mostrar', 'status' => 'pending',
        ]);

        $html = $this->actingAs($this->caio)->get(route('alerts.notifiers'))->assertOk()->getContent();

        foreach (['T0SEGREDO', 'B0SEGREDO', str_repeat('z', 20), 'caminho-secreto-123', 'whsec-nao-mostrar'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        $this->assertStringContainsString('hooks.slack.com/…/fim9', $html);
    }

    // `?canal=` de outro setor com um `?setor=` que a conta configura: vale o canal (404), não o escopo.
    public function test_evidencia_a_foreign_channel_with_a_managed_sector_is_still_404(): void
    {
        $rh = $this->channel($this->ana, ChannelScope::sector($this->acme->id, $this->rh->id));

        $this->actingAs($this->bruno)->get(route('alerts.slack', ['canal' => $rh->id, 'setor' => $this->ti->id]))->assertNotFound();
        $this->actingAs($this->bruno)->get(route('alerts.slack', ['canal' => $rh->id.'abc']))->assertNotFound();
        $this->actingAs($this->bruno)->get(route('alerts.slack', ['canal' => [$rh->id]]))->assertNotFound();
        $this->actingAs($this->bruno)->get(route('alerts.slack', ['setor' => $this->rh->id]))->assertNotFound();
        $this->actingAs($this->bruno)->get(route('alerts.telegram', ['setor' => 'geral']))->assertNotFound();
        $this->actingAs($this->bruno)->get(route('alerts.slack', ['setor' => $this->ti->id]))->assertOk();
    }

    // A conta pessoal com `?canal=` de um canal da empresa abre a tela dela, sem nada do canal da empresa.
    public function test_evidencia_a_personal_account_with_a_company_channel_id_sees_only_its_own(): void
    {
        $general = $this->channel($this->ana, ChannelScope::general($this->acme->id), 'slack', ['slack_channel' => '#diretoria-acme']);
        $pedro = User::factory()->create(['email_verified_at' => now()]);

        $html = $this->actingAs($pedro)->get(route('alerts.slack', ['canal' => $general->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('#diretoria-acme', $html);
        $this->actingAs($pedro)->delete(route('alerts.slack.destroy', ['canal' => $general->id]));
        $this->assertModelExists($general);
    }
}
