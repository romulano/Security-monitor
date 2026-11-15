<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendSlackMessage;
use App\Models\Company;
use App\Models\LookalikeWatch;
use App\Models\NotificationDelivery;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\SubdomainAlert;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\Companies;
use App\Services\Company\DeliveryGate;
use App\Services\Domains\SubdomainCheck;
use App\Services\Lookalike\LookalikeScanner;
use App\Services\Lookalike\RadarSubdomains;
use App\Services\Lookalike\SubdomainAlertRouter;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E4a (DESENHO-5.3 §5.2, P7): o Radar de empresa relê o DNS dos subdomínios ativos do domínio dele e avisa a tomada
 * (CNAME para fora com destino que não resolve) e a mudança para fora, só na passagem de estado, pelos canais do escopo
 * do Radar (E5b1) e com a trava da empresa. DNS sempre pelo dublê: nada sai para a rede.
 */
class RadarSubdomainsTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private LookalikeWatch $watch;

    private OwnedDomain $owned;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        config(['secmon.lookalikes.subdomains_enabled' => true]);
        Settings::set('alerts.realtime_enabled', true);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com.br');
        $this->owned = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'acme.com.br', 'source' => 'company',
            'proof_token' => $this->acme->fresh()->domain_token, 'verified_at' => now(), 'checked_at' => now(), 'check_status' => 'present']);
        $this->watch = $this->watchOf($this->ana, $this->acme);
        $this->watch->forceFill(['status' => 'active', 'completed_at' => now(), 'step_reached' => 3])->save();
        $this->watch->domain->forceFill(['domain' => 'acme.com.br', 'verified_at' => now()])->save();
        // O Radar não tem setor: o aviso vai aos canais gerais da empresa (E5b1).
        $this->companyChannel($this->ana, $this->acme);
    }

    /** Um subdomínio ativo com o DNS de hoje (o que o cadastro gravou), e o DNS do dublê como `$dns`. */
    private function sub(string $name, array $dns): OwnedSubdomain
    {
        foreach ($dns as $type => $values) {
            $this->dns->set($name, $type, $values);
        }

        $check = app(SubdomainCheck::class)->check('acme.com.br', $name);

        return OwnedSubdomain::query()->create(['owned_domain_id' => $this->owned->id, 'name' => $name, 'origin' => 'manual', 'status' => 'active',
            'reason' => $check['reason'], 'facts' => $check['facts'], 'first_seen_at' => now(), 'activated_at' => now()]);
    }

    private function scan(): array
    {
        return app(RadarSubdomains::class)->scan($this->watch->refresh());
    }

    public function test_a_dangling_cname_is_a_critical_takeover_alert_once_and_goes_to_the_general_channels(): void
    {
        $this->dns->set('acme.myshopify.com', 'ips', ['23.227.38.65']);
        $sub = $this->sub('loja.acme.com.br', ['cname' => ['acme.myshopify.com']]);
        $this->assertSame([], SubdomainAlert::query()->pluck('kind')->all());
        $this->scan();
        $this->assertSame(0, SubdomainAlert::query()->count(), 'aponta para fora desde o cadastro (P5) e o destino resolve: nada a avisar');

        // O serviço de fora foi desligado: o destino não resolve mais.
        $this->dns->set('acme.myshopify.com', 'ips', []);
        $lines = $this->scan();
        $alert = SubdomainAlert::query()->sole();
        $this->assertSame(['takeover', 'critical', 'queued', $sub->id], [$alert->kind, $alert->risk, $alert->status, $alert->owned_subdomain_id]);
        $this->assertContains('1 aviso de subdomínio (tomada ou mudança para fora).', $lines);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(['subdomain_alert_id' => $alert->id], $delivery->meta);
        $this->assertStringContainsString('loja.acme.com.br aponta para um destino que não existe', $delivery->payload['text']);
        $this->assertStringContainsString('acme.myshopify.com, que não resolve', json_encode($delivery->payload, JSON_UNESCAPED_UNICODE));
        Queue::assertPushed(SendSlackMessage::class);

        // No dia seguinte, o mesmo estado não avisa de novo; o destino de volta e depois sumindo de novo, avisa.
        $this->scan();
        $this->assertSame(1, SubdomainAlert::query()->count());
        $this->dns->set('acme.myshopify.com', 'ips', ['23.227.38.65']);
        $this->scan();
        $this->dns->set('acme.myshopify.com', 'ips', []);
        $this->scan();
        $this->assertSame(2, SubdomainAlert::query()->where('kind', 'takeover')->count());
    }

    public function test_moving_outside_the_zone_is_a_high_alert_and_a_mute_dns_changes_nothing(): void
    {
        $sub = $this->sub('app.acme.com.br', ['ips' => ['200.10.10.10']]);
        $this->assertFalse($sub->isOutside());

        // DNS mudo: o estado fica o de antes, e não é aviso.
        $this->dns->failing['app.acme.com.br'] = 5;
        $this->assertContains('1 sem resposta do DNS agora: ficam como estavam até a próxima varredura.', $this->scan());
        $this->assertSame(['200.10.10.10'], $sub->refresh()->facts['ips']);
        $this->dns->failing = [];

        // Passou a ser delegado a servidores de DNS de fora.
        $this->dns->set('app.acme.com.br', 'ns', ['ns1.agencia.com']);
        $this->scan();
        $alert = SubdomainAlert::query()->sole();
        $this->assertSame(['outside', 'high'], [$alert->kind, $alert->risk]);
        $this->assertTrue($sub->refresh()->isOutside());
        $this->assertSame('delegado', $sub->reason);
        $this->assertNotNull($sub->last_seen_at);

        // A página do Radar mostra a lista para quem vê o Radar.
        $this->actingAs($this->ana)->get(route('alerts.lookalikes', $this->watch->urlParams()))->assertOk()
            ->assertSee(['Subdomínios vigiados', 'app.acme.com.br', 'aponta para fora', 'O DNS dele fica em servidores de fora']);

        // Na tela de domínios, as frases do Radar (Designer, rodada 13) só com a chave ligada.
        $this->get(route('company.domains'))->assertOk()
            ->assertSee(['Os cadastrados aqui entram no Radar.', 'O Radar vigia imitações dele, mas não o trata como servidor da empresa.']);
        config(['secmon.lookalikes.subdomains_enabled' => false]);
        $this->get(route('company.domains'))->assertOk()->assertDontSee('entram no Radar');
    }

    public function test_nothing_runs_with_the_switch_off_on_a_personal_radar_or_with_the_company_suspended(): void
    {
        $this->sub('loja.acme.com.br', ['cname' => ['acme.myshopify.com']]);

        config(['secmon.lookalikes.subdomains_enabled' => false]);
        $this->assertSame([], $this->scan());
        $this->actingAs($this->ana)->get(route('alerts.lookalikes', $this->watch->urlParams()))->assertOk()->assertDontSee('Subdomínios vigiados');

        config(['secmon.lookalikes.subdomains_enabled' => true]);
        app(Companies::class)->suspend($this->acme);
        $this->assertSame([], $this->scan());

        $personal = $this->client();
        $watch = $this->watchOf($personal);
        $this->assertFalse(app(RadarSubdomains::class)->enabled($watch));
        $this->assertSame(0, SubdomainAlert::query()->count());
    }

    // E5b1: o aviso do Radar do TI vai ao canal do TI, mesmo com quem o criou fora do setor; não vai ao geral (D5).
    public function test_the_sector_radar_warns_the_sector_channel_whoever_created_it(): void
    {
        $sector = $this->companySector($this->acme, 'TI');
        $bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($bruno, $sector, manager: true);
        $this->watch->forceFill(['user_id' => $bruno->id, 'sector_id' => $sector->id])->save();
        $ti = $this->companyChannel($bruno, $this->acme, $sector);
        $this->sub('loja.acme.com.br', ['cname' => ['acme.myshopify.com'], 'ips' => []]);
        $sector->users()->detach($bruno->id);
        $this->refreshVisibility();

        $this->dns->set('acme.myshopify.com', 'ips', ['23.227.38.65']);
        $this->scan();
        $this->dns->set('acme.myshopify.com', 'ips', []);
        $this->scan();
        $alert = SubdomainAlert::query()->sole();
        $this->assertSame('queued', $alert->status);
        $this->assertSame([$ti->id], NotificationDelivery::query()->pluck('channel_id')->all());

        // Empresa suspensa: nada sai (D7) — a trava do aviso já pronto para sair.
        app(Companies::class)->suspend($this->acme);
        $this->refreshVisibility();
        app(SubdomainAlertRouter::class)->route($paused = $alert->replicate()->fill(['status' => 'pending']));
        $this->assertSame(DeliveryGate::PAUSED, $paused->fresh()->note);
        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    public function test_the_radar_only_watches_the_subdomains_of_a_domain_that_serves_its_sector(): void
    {
        $this->sub('loja.acme.com.br', ['ips' => ['200.10.10.10']]);
        $ti = $this->companySector($this->acme, 'TI');
        $rh = $this->companySector($this->acme, 'RH');

        // Domínio de um setor, Radar de outro (dados de antes da regra): o Radar não lê os subdomínios dele (P1).
        $this->owned->forceFill(['sector_id' => $ti->id])->save();
        $this->watch->forceFill(['sector_id' => $rh->id])->save();
        $this->assertSame(0, app(RadarSubdomains::class)->watched($this->watch->refresh())->count());

        $this->watch->forceFill(['sector_id' => $ti->id])->save();
        $this->assertSame(['loja.acme.com.br'], app(RadarSubdomains::class)->watched($this->watch->refresh())->pluck('name')->all());
    }

    public function test_the_full_scan_reads_the_subdomains_and_logs_it(): void
    {
        config(['secmon.lookalikes.max_variants' => 20, 'secmon.lookalikes.dns_retry_pause_ms' => 0]);
        Http::fake(['*' => Http::response([])]);
        $this->sub('www.acme.com.br', ['ips' => ['200.10.10.10']]);

        app(LookalikeScanner::class)->scan($this->watch->refresh());

        $this->assertContains('Subdomínios vigiados: 1 conferido no DNS (só DNS; nada é aberto nos servidores da empresa).', collect($this->watch->refresh()->last_log)->pluck('text')->all());
    }
}
