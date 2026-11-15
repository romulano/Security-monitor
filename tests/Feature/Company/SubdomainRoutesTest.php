<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\CompanySubdomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** E3b: as rotas de cadastrar e tirar subdomínio e a parcial da tela do domínio (a tela em si vem da E3a3). */
class SubdomainRoutesTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private User $bruno;

    private User $caio;

    private User $carla;

    private OwnedDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $ti = $this->companySector($this->acme, 'TI');
        $rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $ti);
        $this->carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->carla, $rh, manager: true);
        $this->domain = $this->domainOf($this->acme, $this->ana, 'acme.com.br', $ti);
    }

    private function domainOf(Company $company, User $by, string $name, ?Sector $sector): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate([
            'user_id' => $by->id, 'company_id' => $company->id, 'sector_id' => $sector?->id, 'domain' => $name, 'source' => 'radar',
            'proof_token' => $company->domain_token, 'verified_at' => now(),
        ]);
    }

    public function test_whoever_configures_adds_and_removes_and_the_others_get_404_or_a_refusal(): void
    {
        $this->dns->set('login.acme.com.br', 'ips', ['203.0.113.10']);
        $this->dns->set('loja.acme.com.br', 'cname', ['shops.myshopify.com'])->set('shops.myshopify.com', 'ips', ['23.227.38.65']);
        $store = fn (User $who, string $name, ?OwnedDomain $domain = null) => $this->actingAs($who)->from('/empresa/dominios')
            ->post(route('company.subdomains.store', $domain ?? $this->domain), ['nome' => $name]);

        $store($this->bruno, 'login.acme.com.br')->assertRedirect('/empresa/dominios')->assertSessionHas('status', 'login.acme.com.br cadastrado.');
        $store($this->ana, 'loja.acme.com.br')->assertSessionHas('status', fn (string $status) => $status === 'loja.acme.com.br cadastrado. Hospedado fora: aponta (CNAME) para um serviço de fora (shops.myshopify.com).');

        // O membro vê o domínio e recebe a recusa; o RH não vê (404); outra empresa, 404.
        $store($this->caio, 'x.acme.com.br')->assertSessionHas('error', 'Só a admin e quem responde pelo setor do domínio cadastram e tiram subdomínios.');
        $store($this->carla, 'x.acme.com.br')->assertNotFound();
        [$beta, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $store($paulo, 'x.acme.com.br')->assertNotFound();
        $store($this->ana, 'x.beta.com.br', $this->domainOf($beta, $paulo, 'beta.com.br', null))->assertNotFound();
        $store($this->ana, '')->assertSessionHasErrors(['nome' => 'Escreva o subdomínio, como login.empresa.com.br.']);

        $login = OwnedSubdomain::query()->where('name', 'login.acme.com.br')->sole();
        $this->actingAs($this->carla)->delete(route('company.subdomains.remove', $login))->assertNotFound();
        $this->actingAs($this->caio)->from('/empresa/dominios')->delete(route('company.subdomains.remove', $login))->assertSessionHas('error');
        $this->actingAs($this->bruno)->from('/empresa/dominios')->delete(route('company.subdomains.remove', $login))->assertRedirect('/empresa/dominios');
        $this->assertSame('ignored', $login->refresh()->status);
    }

    public function test_the_partial_shows_the_list_with_reasons_and_buttons_only_for_who_configures(): void
    {
        foreach (['login', 'www'] as $label) {
            $this->dns->set("{$label}.acme.com.br", 'ips', ['203.0.113.10']);
        }
        $this->dns->set('loja.acme.com.br', 'cname', ['shops.myshopify.com'])->set('shops.myshopify.com', 'ips', ['23.227.38.65']);
        $subdomains = app(CompanySubdomains::class);
        $subdomains->add($this->ana, $this->domain, 'login.acme.com.br');
        $subdomains->add($this->ana, $this->domain, 'loja.acme.com.br');
        $subdomains->remove($this->ana, $subdomains->add($this->ana, $this->domain, 'www.acme.com.br'));
        $this->domain->subdomains()->forceCreate(['name' => 'vpn.acme.com.br', 'origin' => 'passive', 'status' => 'suggested', 'reason' => 'delegado', 'first_seen_at' => now()]);

        $render = fn (User $who) => view('company.partials.subdomains', ['domain' => $this->domain, 'subs' => $subdomains->screen($who, $this->domain)])->render();

        $html = $render($this->bruno);
        $this->assertStringContainsString('2 de 20', $html);
        $this->assertStringContainsString('login.acme.com.br', $html);
        $this->assertStringContainsString('Hospedado fora: aponta (CNAME) para um serviço de fora (shops.myshopify.com).', $html);
        $this->assertStringNotContainsString('Radar', $html);
        $this->assertStringContainsString('Sugestões: achados em certificados públicos e no DNS', $html);
        $this->assertStringContainsString('data-confirm-tone="primary"', $html);
        $this->assertStringContainsString('O DNS dele fica em servidores de fora', $html);
        $this->assertStringContainsString('www.acme.com.br · tirado', $html);
        $this->assertStringContainsString(route('company.subdomains.store', $this->domain), $html);
        $this->assertStringContainsString('data-confirm="Tirar login.acme.com.br', $html);

        // O membro vê a lista, sem os botões.
        $member = $render($this->caio);
        $this->assertStringContainsString('login.acme.com.br', $member);
        $this->assertStringNotContainsString(route('company.subdomains.store', $this->domain), $member);
        $this->assertStringNotContainsString('data-confirm=', $member);
    }

    public function test_the_domains_screen_shows_the_subdomains_and_refuses_a_covered_name_as_a_domain(): void
    {
        $this->dns->set('login.acme.com.br', 'ips', ['203.0.113.10']);
        app(CompanySubdomains::class)->add($this->ana, $this->domain, 'login.acme.com.br');

        $this->actingAs($this->bruno)->get(route('company.domains'))->assertOk()
            ->assertSee('Subdomínios')->assertSee('login.acme.com.br')->assertSee(route('company.subdomains.store', $this->domain), false);
        $this->actingAs($this->caio)->get(route('company.domains'))->assertOk()
            ->assertSee('login.acme.com.br')->assertDontSee(route('company.subdomains.store', $this->domain), false);

        // Cadastrar x.acme.com.br como domínio no setor do de cima (o TI): é subdomínio (decisão do Designer), e nada é
        // cadastrado. Sem setor, o domínio do TI não o cobre (P1, Y1 do Red Team da E3b1).
        $ti = $this->domain->sector_id;
        $this->actingAs($this->ana)->from(route('company.domains'))->post(route('company.domains.store'), ['dominio' => 'loja.acme.com.br', 'setor' => $ti])
            ->assertSessionHas('error', 'Já coberto por acme.com.br. Cadastre como subdomínio.');
        $this->assertSame(1, OwnedDomain::query()->count());
    }

    public function test_an_old_name_registered_as_a_domain_under_another_shows_the_way_to_free_the_slot(): void
    {
        $old = $this->domainOf($this->acme, $this->ana, 'ti.acme.com.br', null);

        $this->actingAs($this->ana)->get(route('company.domains'))->assertOk()
            ->assertSeeInOrder(['ti.acme.com.br', 'Já coberto por acme.com.br: dá para remover e cadastrar como subdomínio, liberando a vaga.'])
            ->assertSeeInOrder(['acme.com.br', 'Setor', 'Subdomínios', 'Remover este domínio']);
        $this->assertNotNull($old->id);
    }
}
