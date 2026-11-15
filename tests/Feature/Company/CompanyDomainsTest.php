<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyAuditEntry;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\CompanyAudit;
use App\Services\Domains\DomainOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E3a3 (DESENHO-5.3 §2.7): a tela Domínios da empresa. Cada conta vê os domínios do que vê (R10); quem configura (a admin;
 * o responsável, os do setor dele) cadastra, comprova, troca o setor e remove (com a reautenticação e a contagem do que perde
 * a posse). O TXT da empresa só para quem configura (P10).
 * Conta pessoal, conta de outro setor e de outra empresa: 404.
 */
class CompanyDomainsTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private User $bruno;

    private User $caio;

    private Sector $ti;

    private Sector $rh;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
    }

    private function add(User $who, string $name, ?Sector $sector = null)
    {
        return $this->actingAs($who)->from(route('company.domains'))
            ->post(route('company.domains.store'), ['dominio' => $name, 'setor' => $sector?->id]);
    }

    private function owned(string $name): OwnedDomain
    {
        return OwnedDomain::query()->where('company_id', $this->acme->id)->where('domain', $name)->sole();
    }

    private function token(): string
    {
        return 'secmon-verify='.$this->acme->refresh()->domain_token;
    }

    private function remove(User $who, OwnedDomain $owned, string $counts = '0-0-0', array $extra = ['reauth_password' => 'password'])
    {
        return $this->actingAs($who)->from(route('company.domains'))
            ->delete(route('company.domains.remove'), ['dominio' => $owned->id.'-'.$counts] + $extra);
    }

    public function test_each_account_sees_the_domains_of_what_it_sees_and_only_who_configures_sees_the_txt(): void
    {
        $this->add($this->ana, 'acme.com.br')->assertRedirect();
        $this->add($this->ana, 'acmeti.com.br', $this->ti)->assertRedirect();
        $this->add($this->ana, 'acmerh.com.br', $this->rh)->assertRedirect();
        $this->assertSame([null, $this->ti->id, $this->rh->id], [$this->owned('acme.com.br')->sector_id, $this->owned('acmeti.com.br')->sector_id, $this->owned('acmerh.com.br')->sector_id]);

        $this->actingAs($this->ana)->get(route('company.domains'))->assertOk()
            ->assertSee(['acme.com.br', 'acmeti.com.br', 'acmerh.com.br', '3 de 15 domínios.', $this->token(), 'Cadastrar domínio'])
            ->assertSee('aria-label="Empresa"', false)->assertSee(['Contas', 'Setores', 'Atividade']);

        // O responsável de TI: os de TI (com o TXT, configura) e o sem setor (só vê); o de RH, não.
        $page = $this->actingAs($this->bruno)->get(route('company.domains'))->assertOk()
            ->assertSee(['acmeti.com.br', 'Cadastrar domínio'])->assertDontSee('acmerh.com.br')->assertDontSee('Setores');
        // O TXT só no domínio de TI: o valor (visível e o do Copiar) e as instruções para copiar.
        $this->assertSame(3, substr_count($page->getContent(), $this->token()));
        $this->assertSame(1, substr_count($page->getContent(), 'Copiar as instruções'));
        $this->assertSame(1, substr_count($page->getContent(), 'data-confirm-choice'));
        $this->assertSame(1, substr_count($page->getContent(), 'Remover este domínio'));

        // O membro: vê os dois, sem o TXT, sem cadastrar e sem trocar o setor.
        $this->actingAs($this->caio)->get(route('company.domains'))->assertOk()
            ->assertSee(['acme.com.br', 'acmeti.com.br', 'Aguardando o registro TXT. Quem configura o domínio vê o registro e comprova.'])
            ->assertDontSee('acmerh.com.br')->assertDontSee($this->token())->assertDontSee('Cadastrar domínio')
            ->assertDontSee('Trocar o setor')->assertDontSee('Remover este domínio')->assertDontSee('de 15 domínios');

        // A conta pessoal e a outra empresa.
        $this->actingAs(User::factory()->create())->get(route('company.domains'))->assertNotFound();
        [, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $this->actingAs($paulo)->get(route('company.domains'))->assertOk()->assertDontSee('acme.com.br')->assertSee('A empresa ainda não tem domínios aqui.');
    }

    public function test_adding_follows_the_sector_rules_the_limit_and_never_tells_the_hidden_sector(): void
    {
        $this->add($this->bruno, 'https://www.Loja.Acme.com.br/entrar', $this->ti)->assertRedirect(route('company.domains').'#dominio-'.$this->owned('loja.acme.com.br')->id);
        $this->assertSame([$this->ti->id, $this->bruno->id, 'company'], [$this->owned('loja.acme.com.br')->sector_id, $this->owned('loja.acme.com.br')->user_id, $this->owned('loja.acme.com.br')->source]);

        // O responsável não cadastra sem setor nem em setor que não é dele; o membro não cadastra.
        $this->add($this->bruno, 'semsetor.com.br')->assertSessionHas('error', 'Escolha um setor que você pode usar.');
        $this->add($this->bruno, 'outro.com.br', $this->rh)->assertSessionHas('error', 'Escolha um setor que você pode usar.');
        $this->add($this->caio, 'membro.com.br', $this->ti)->assertSessionHas('error');
        $this->add($this->ana, '10.0.0.1')->assertSessionHas('error', 'Informe um domínio válido, como empresa.com.br (sem IP, porta ou domínio interno).');
        $this->add($this->ana, 'com.br')->assertSessionHas('error');

        // Já cadastrado num setor que quem pede não vê: sem dizer qual.
        $this->add($this->ana, 'folha.acme.com.br', $this->rh);
        $this->add($this->bruno, 'folha.acme.com.br', $this->ti)
            ->assertSessionHas('error', 'folha.acme.com.br já está cadastrado na empresa, num setor que você não vê. Peça a quem administra a empresa.');
        $this->add($this->ana, 'folha.acme.com.br')->assertSessionHas('error', 'folha.acme.com.br já está na lista.');

        // O limite conta toda linha da empresa, pendente ou comprovada.
        $this->acme->forceFill(['limit_domains' => 3])->save();
        $this->add($this->ana, 'terceiro.com.br');
        $this->add($this->ana, 'quarto.com.br')->assertSessionHas('error', 'A empresa chegou ao limite de 3 domínios. Remova um em Domínios da empresa para cadastrar outro.');
        $this->assertSame(3, OwnedDomain::query()->where('company_id', $this->acme->id)->count());

        $this->assertSame(['Domínio cadastrado: loja.acme.com.br (setor: TI)', 'Domínio cadastrado: terceiro.com.br (setor: sem setor)'], CompanyAuditEntry::query()
            ->where('action', 'domain.added')->whereIn('target_label', ['loja.acme.com.br', 'terceiro.com.br'])->orderBy('id')->get()
            ->map(fn ($entry) => app(CompanyAudit::class)->describe($entry))->all());
    }

    public function test_verifying_proves_with_the_company_txt_and_reaches_the_copies(): void
    {
        $this->add($this->bruno, 'loja.com.br', $this->ti);
        $owned = $this->owned('loja.com.br');
        $profile = $this->profileOf($this->bruno, $this->acme, $this->ti);
        $copy = $profile->domains()->create(['user_id' => $this->bruno->id, 'domain' => 'app.loja.com.br', 'verify_token' => Str::random(32)]);

        $this->actingAs($this->bruno)->from(route('company.domains'))->post(route('company.domains.verify', $owned))
            ->assertSessionHas('error', 'Ainda não encontramos o TXT '.$this->token().' em loja.com.br. Registros novos podem levar alguns minutos para propagar.');
        $this->assertNull($owned->refresh()->verified_at);

        // O TXT de uma conta (o da Ana, pessoal) não prova para a empresa.
        $this->dns->set('loja.com.br', 'txt', ['secmon-verify='.app(DomainOwnership::class)->token($this->ana)]);
        $this->actingAs($this->bruno)->post(route('company.domains.verify', $owned))->assertSessionHas('error');

        $this->dns->set('loja.com.br', 'txt', [$this->token()]);
        $this->actingAs($this->bruno)->post(route('company.domains.verify', $owned))->assertSessionHas('status', 'Posse de loja.com.br comprovada pelo TXT em loja.com.br.');
        $this->assertNotNull($owned->refresh()->verified_at);
        $this->assertNotNull($copy->refresh()->verified_at, 'o subdomínio do alerta da empresa usa a posse');

        $this->actingAs($this->caio)->get(route('company.domains'))->assertSee('Posse comprovada em')->assertDontSee($this->token());
    }

    public function test_removing_asks_for_the_reauth_and_the_count_shown_and_drops_the_copies(): void
    {
        $this->add($this->bruno, 'loja.com.br', $this->ti);
        $owned = $this->owned('loja.com.br');
        $watch = $this->watchOf($this->bruno, $this->acme, $this->ti);
        $watch->domain->forceFill(['domain' => 'loja.com.br'])->save();
        $this->dns->set('loja.com.br', 'txt', [$this->token()]);
        $this->actingAs($this->bruno)->post(route('company.domains.verify', $owned));
        $this->assertNotNull($watch->domain->refresh()->verified_at);

        $this->actingAs($this->bruno)->get(route('company.domains'))
            ->assertSee('1 Radar perde a posse e para de vigiar este domínio até ele ser comprovado de novo. Nada é apagado.')
            ->assertSee('value="'.$owned->id.'-0-1-0"', false);

        // Sem a senha, com a contagem velha, como membro ou de outro setor: nada sai.
        $this->remove($this->bruno, $owned, '0-1-0', [])->assertSessionHasErrors('reauth_password', null, 'reauth');
        $this->remove($this->bruno, $owned, '0-0-0')->assertSessionHas('error', 'O que depende de loja.com.br mudou desde que você abriu a página: 1 Radar perde a posse e para de vigiar este domínio até ele ser comprovado de novo. Nada é apagado. Confira e remova de novo.');
        $this->remove($this->caio, $owned, '0-1-0')->assertSessionHas('error', 'Escolha um domínio que você configura.');
        [, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $paulo->forceFill(['password' => 'password'])->save();
        $this->remove($paulo, $owned, '0-1-0')->assertSessionHas('error', 'Escolha um domínio que você configura.');
        $this->assertTrue($owned->exists());

        $this->remove($this->bruno, $owned, '0-1-0')->assertRedirect(route('company.domains'))
            ->assertSessionHas('status', 'Domínio removido. 1 Radar perde a posse e para de vigiar este domínio até ele ser comprovado de novo. Nada é apagado.');
        $this->assertNull(OwnedDomain::query()->find($owned->id));
        $this->assertNull($watch->domain->refresh()->verified_at, 'a cópia do Radar perde a posse na mesma hora');
        $this->assertSame('Domínio removido: loja.com.br (setor: TI). 1 Radar perde a posse e para de vigiar este domínio até ele ser comprovado de novo. Nada é apagado.', app(CompanyAudit::class)
            ->describe(CompanyAuditEntry::query()->where('action', 'domain.removed')->sole()));
    }

    public function test_removing_brings_back_the_limit_notices(): void
    {
        $this->acme->forceFill(['limit_domains' => 1])->save();
        $this->add($this->ana, 'loja.com.br');
        $this->assertSame([80, 100], DB::table('company_limit_notices')->where('company_id', $this->acme->id)->orderBy('level')->pluck('level')->all());

        $this->ana->forceFill(['password' => 'password'])->save();
        $this->remove($this->ana, $this->owned('loja.com.br'))->assertSessionHas('status');
        // Abaixo dos marcos de novo (E3d): o próximo cadastro que chegar ao limite avisa outra vez.
        $this->assertSame(0, DB::table('company_limit_notices')->where('company_id', $this->acme->id)->count());
    }

    public function test_changing_the_sector_follows_item_sectors_and_lands_in_the_activity(): void
    {
        $this->add($this->ana, 'loja.com.br', $this->ti);
        $owned = $this->owned('loja.com.br');

        $this->actingAs($this->bruno)->from(route('company.domains'))->put(route('company.domains.sector', $owned), ['setor' => 'sem'])->assertSessionHas('error', 'Escolha um setor que você pode usar.');
        $this->actingAs($this->ana)->put(route('company.domains.sector', $owned), ['setor' => $this->rh->id])->assertSessionHas('status', 'Setor de loja.com.br trocado.');
        $this->assertSame($this->rh->id, $owned->refresh()->sector_id);
        $this->assertSame('Domínio loja.com.br: setor TI → RH', app(CompanyAudit::class)
            ->describe(CompanyAuditEntry::query()->where('action', 'item.sector_changed')->sole()));

        // Agora em RH: o responsável de TI já não vê nem troca.
        $this->actingAs($this->bruno)->put(route('company.domains.sector', $owned), ['setor' => $this->ti->id])->assertNotFound();
        $this->actingAs($this->bruno)->get(route('company.domains'))->assertDontSee('loja.com.br');
    }

    public function test_the_domain_does_not_go_to_a_sector_that_would_leave_an_item_without_the_ownership(): void
    {
        $this->add($this->ana, 'loja.com.br');
        $owned = $this->owned('loja.com.br');
        $profile = $this->profileOf($this->bruno, $this->acme, $this->ti);
        $profile->domains()->create(['user_id' => $this->bruno->id, 'domain' => 'app.loja.com.br', 'verify_token' => Str::random(32)]);

        // A tela já diz para onde não dá (P1 da E3a2: o alerta de TI ficaria sem a posse).
        $this->actingAs($this->ana)->get(route('company.domains'))->assertSee(['RH — indisponível: um alerta ou Radar de outro setor usa este domínio',
            'Alguns setores estão indisponíveis porque alertas ou Radares de outros setores usam este domínio. Troque o setor deles antes.']);
        $this->actingAs($this->ana)->from(route('company.domains'))->put(route('company.domains.sector', $owned), ['setor' => $this->rh->id])
            ->assertSessionHas('error', '1 alerta ou Radar de outro setor usa este domínio. Mova-os antes, ou deixe o domínio sem setor.');
        $this->assertNull($owned->refresh()->sector_id);

        $this->actingAs($this->ana)->put(route('company.domains.sector', $owned), ['setor' => $this->ti->id])->assertSessionHas('status');
        $this->assertSame($this->ti->id, $owned->refresh()->sector_id);
    }

    /** Teste D: as rotas com o domínio na URL dão 404 para quem não configura, de outro setor e de outra empresa. */
    public function test_rt_the_routes_with_the_domain_in_the_url_are_a_404_for_who_does_not_configure_it(): void
    {
        $this->add($this->ana, 'rh.com.br', $this->rh);
        $this->add($this->ana, 'semsetor.com.br');
        [, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $personal = User::factory()->create();

        foreach (['rh.com.br' => [$this->bruno, $this->caio, $paulo, $personal], 'semsetor.com.br' => [$this->bruno, $this->caio, $paulo, $personal]] as $name => $outsiders) {
            $owned = $this->owned($name);

            foreach ($outsiders as $who) {
                $this->actingAs($who)->post(route('company.domains.verify', $owned))->assertNotFound();
                $this->actingAs($who)->put(route('company.domains.sector', $owned), ['setor' => $this->ti->id])->assertNotFound();
            }

            $this->assertSame($owned->sector_id, $owned->refresh()->sector_id);
        }

        // A cópia pessoal com o mesmo id de rota não vale: a conta pessoal nem chega à tela.
        $this->actingAs($personal)->post(route('company.domains.store'), ['dominio' => 'pessoal.com.br'])->assertNotFound();
        $this->assertSame(0, MonitoredDomain::query()->count());
    }

    // E3b (decisão do Designer): o nome abaixo de um domínio da empresa é subdomínio, não domínio novo.
    public function test_a_name_under_a_company_domain_is_refused_as_a_domain(): void
    {
        $this->add($this->ana, 'acme.com.br')->assertRedirect();

        $this->add($this->ana, 'loja.acme.com.br')->assertSessionHas('error', 'Já coberto por acme.com.br. Cadastre como subdomínio.');
        $this->assertSame(['acme.com.br'], OwnedDomain::query()->where('company_id', $this->acme->id)->pluck('domain')->all());
    }
}
