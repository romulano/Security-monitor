<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Services\Domains\SubdomainCheck;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E3b1. `rt_*`: passava no 01d0a0e (com a E3d mesclada, 1d70798) e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * - Y1 (P1, 02-e3b1-y1.diff): o "já coberto por…" do `claim()` devolve a linha de cima **de qualquer setor**. Com o
 *   domínio principal no TI, o RH não usa mais nenhum subdomínio dele (nem cadastra o próprio): o claim recusa com
 *   OTHER_SECTOR, o `check()` pula o nome coberto e nunca procura o TXT nele. "Coberto" vale só pela linha de cima que
 *   serve ao setor do item.
 * - Y2: o trigger deixa o subdomínio trocar de domínio por update (para o domínio homônimo de outra empresa) e grava nome
 *   com maiúscula ou rótulo vazio (o único é por nome exato).
 * - Y3: o `SubdomainCheck` só olha a zona do próprio nome: um CNAME para um nome da empresa numa zona filha delegada a
 *   terceiro (ou num nome delegado) passa como infraestrutura própria, sem `outside`.
 */
class RedTeamE3b1Test extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $carla;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->carla, $this->rh, manager: true);
    }

    private function domain(string $name, ?Sector $sector, ?Company $company = null, ?User $user = null): OwnedDomain
    {
        $company ??= $this->acme;
        $user ??= $this->ana;

        return OwnedDomain::query()->forceCreate(['user_id' => $user->id, 'company_id' => $company->id, 'sector_id' => $sector?->id,
            'domain' => $name, 'source' => 'company', 'proof_token' => $company->domain_token, 'verified_at' => now()]);
    }

    private function refused(callable $sql): bool
    {
        try {
            DB::transaction($sql);
        } catch (QueryException) {
            return true;
        }

        return false;
    }

    public function test_rt_y1_a_parent_of_another_sector_does_not_lock_its_subdomains_away(): void
    {
        $this->domain('acme.com.br', $this->ti);
        $this->dns->set('loja.acme.com.br', 'txt', [DomainOwnership::PREFIX.$this->acme->domain_token]);

        // No 1d70798: OTHER_SECTOR (a linha de cima do TI "cobre" o nome, e não serve ao RH).
        $watch = app(RadarInterview::class)->saveDomain($this->carla, null, null, 'loja.acme.com.br', $this->rh->id);
        $this->assertSame($this->rh->id, OwnedDomain::query()->where('domain', 'loja.acme.com.br')->value('sector_id'));
        $this->assertTrue(app(DomainOwnership::class)->verifyRow($watch->domain, 'radar'), 'o TXT no próprio nome prova para o RH');

        // O TI continua coberto pelo de cima, sem linha nova (não gasta um dos 15).
        $ti = DomainOwner::company($this->acme->id, $this->ana->id, $this->ti->id);
        $this->assertSame('acme.com.br', app(DomainOwnership::class)->claim($ti, 'app.acme.com.br', 'radar')->domain);
        $this->assertSame(2, OwnedDomain::query()->where('company_id', $this->acme->id)->count());
    }

    public function test_rt_y2_the_subdomain_never_changes_domain_nor_takes_a_malformed_name(): void
    {
        [$beta, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $acme = $this->domain('acme.com.br', null);
        $other = $this->domain('acme.com.br', null, $beta, $paulo);
        $insert = fn (string $name) => fn () => DB::table('owned_subdomains')->insertGetId(['owned_domain_id' => $acme->id, 'name' => $name,
            'origin' => 'manual', 'status' => 'active', 'facts' => '{}', 'first_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $id = DB::transaction($insert('login.acme.com.br'));
        $this->assertTrue($this->refused(fn () => DB::table('owned_subdomains')->where('id', $id)->update(['owned_domain_id' => $other->id])), 'para a Beta');
        $this->assertTrue($this->refused(fn () => DB::table('owned_subdomains')->where('id', $id)->update(['name' => 'outro.acme.com.br'])), 'trocar o nome');
        foreach (['LOGIN.acme.com.br', '.acme.com.br', 'a..acme.com.br', '_dmarc.acme.com.br', '*.acme.com.br', 'a b.acme.com.br'] as $bad) {
            $this->assertTrue($this->refused($insert($bad)), $bad);
        }
        $this->assertSame($acme->id, (int) DB::table('owned_subdomains')->where('id', $id)->value('owned_domain_id'));
    }

    public function test_rt_y3_a_cname_into_a_delegated_part_of_the_zone_is_outside(): void
    {
        $check = fn (string $name) => app(SubdomainCheck::class)->check('acme.com.br', $name);

        // www → x.filha.acme.com.br, e filha.acme.com.br é delegada à agência: quem responde pelo IP é a agência.
        $this->dns->set('www.acme.com.br', 'cname', ['x.filha.acme.com.br'])->set('filha.acme.com.br', 'ns', ['ns1.agencia.com'])
            ->set('x.filha.acme.com.br', 'ips', ['198.51.100.7']);
        $www = $check('www.acme.com.br');
        $this->assertSame([true, true], [$www['resolves'], $www['outside']]);

        // portal → app.acme.com.br, e app é um corte de zona com NS de fora.
        $this->dns->set('portal.acme.com.br', 'cname', ['app.acme.com.br'])->set('app.acme.com.br', 'ns', ['ns1.agencia.com'])
            ->set('app.acme.com.br', 'ips', ['198.51.100.8']);
        $this->assertTrue($check('portal.acme.com.br')['outside']);

        // O CNAME para o próprio domínio principal (com os NS do provedor) continua dentro.
        $this->dns->set('site.acme.com.br', 'cname', ['acme.com.br'])->set('acme.com.br', 'ns', ['ada.ns.cloudflare.com'])
            ->set('acme.com.br', 'ips', ['104.21.1.1']);
        $this->assertSame([true, false], [$check('site.acme.com.br')['resolves'], $check('site.acme.com.br')['outside']]);
    }

    public function test_evidencia_the_personal_account_is_never_covered_by_a_company_domain(): void
    {
        $this->domain('acme.com.br', null);
        $pedro = User::factory()->create();
        $token = app(DomainOwnership::class)->token($pedro);
        $this->dns->set('loja.acme.com.br', 'txt', [DomainOwnership::PREFIX.$token]);

        // A conta pessoal com o TXT dela no nome prova o nome dela, sem ler a linha da empresa (nem ser coberta por ela).
        $proof = app(DomainOwnership::class)->verify(DomainOwner::account($pedro->id), 'loja.acme.com.br', 'radar');
        $this->assertSame([null, $pedro->id, 'loja.acme.com.br'], [$proof->company_id, $proof->user_id, $proof->domain]);
        $this->assertNull(app(DomainOwnership::class)->covering(DomainOwner::account($pedro->id), 'x.loja.acme.com.br')?->company_id);
    }
}
