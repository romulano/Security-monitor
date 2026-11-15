<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\DomainOwnership;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E3a3a. `rt_*`: passava no b24615c e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * G1 (P10): a tela do Radar mostra o quadro da posse (`<x-domain-ownership>`) a quem só **vê** o Radar da empresa (o
 * membro do setor), com o TXT da empresa no valor, no campo escondido do Copiar e agora também no "Copiar as
 * instruções". A tela de Domínios da empresa esconde o TXT de quem só vê; a do Radar não.
 */
class RedTeamE3a3aTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private User $bruno;

    private User $caio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
    }

    public function test_rt_g1_the_radar_page_never_shows_the_company_txt_to_who_only_sees_it(): void
    {
        $watch = app(RadarInterview::class)->saveDomain($this->bruno, null, null, 'loja.acme.com.br', $this->ti->id);
        $watch->forceFill(['status' => 'waiting', 'step_reached' => 3, 'completed_at' => now()])->save();
        $token = $this->acme->refresh()->domain_token;

        // Quem configura (o responsável do TI) vê o TXT para publicar.
        $this->actingAs($this->bruno)->get(route('alerts.lookalikes', ['radar' => $watch->id]))->assertOk()->assertSee($token);

        // O membro do TI vê o Radar, mas não o TXT da empresa (no b24615c: no valor, no campo e nas instruções).
        $this->actingAs($this->caio)->get(route('alerts.lookalikes', ['radar' => $watch->id]))->assertOk()
            ->assertSee('loja.acme.com.br')->assertDontSee($token)->assertSee('Quem configura este Radar vê o registro');

        // O TXT sumiu de um Radar ativo: o membro vê o aviso, sem o TXT.
        $owned = OwnedDomain::query()->where('domain', 'loja.acme.com.br')->sole();
        $owned->forceFill(['proof_token' => $token, 'verified_at' => now()->subMonth(), 'unseen_since' => now()->subDay()])->save();
        $watch->forceFill(['status' => 'active'])->save();
        app(DomainOwnership::class)->apply($watch->domain->refresh());
        $this->actingAs($this->caio)->get(route('alerts.lookalikes', ['radar' => $watch->id]))->assertOk()
            ->assertSee('sumiu do DNS')->assertDontSee($token);
        $this->actingAs($this->bruno)->get(route('alerts.lookalikes', ['radar' => $watch->id]))->assertOk()->assertSee($token);
    }

    public function test_evidencia_the_personal_account_gets_a_404_before_the_throttle_and_the_binding(): void
    {
        $pedro = User::factory()->create();
        $owned = OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'acme.com.br', 'source' => 'company']);
        $this->actingAs($pedro);

        // Acima do limite de cada rota (6 e 20 por minuto), e com id que não existe ou não cabe no bigint: sempre 404.
        foreach (range(1, 25) as $i) {
            $this->post(route('company.domains.verify', $owned))->assertNotFound();
            $this->post(route('company.domains.store'), ['dominio' => "x{$i}.com.br"])->assertNotFound();
        }
        $this->post('/empresa/dominios/99999999999999999999999/verificar')->assertNotFound();
        $this->get(route('company.domains'))->assertNotFound();
        $this->assertSame(1, OwnedDomain::query()->count());

        // O membro: lista sem o TXT (nem no campo escondido nem nas instruções), e cadastrar é recusado sem criar nada.
        $this->actingAs($this->caio)->get(route('company.domains'))->assertOk()->assertSee('acme.com.br')
            ->assertDontSee($this->acme->refresh()->domain_token)->assertDontSee('Copiar as instruções');
        $this->actingAs($this->caio)->from(route('company.domains'))->post(route('company.domains.store'), ['dominio' => 'novo.com.br', 'setor' => $this->ti->id])
            ->assertSessionHas('error');
        $this->assertSame(1, OwnedDomain::query()->count());
    }
}
