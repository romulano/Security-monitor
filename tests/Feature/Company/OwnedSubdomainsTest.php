<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\CompanyAuditEntry;
use App\Models\EmailMessage;
use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\CompanyAudit;
use App\Services\Domains\CompanySubdomains;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Services\Domains\SubdomainCheck;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\Support\OwnershipHelpers;
use Tests\TestCase;

/** E3b (DESENHO-5.3 §3.1–3.2): os subdomínios da empresa — a tabela, o DNS e o cadastro à mão. O DNS é sempre o dublê. */
class OwnedSubdomainsTest extends TestCase
{
    use OwnershipHelpers;
    use RefreshDatabase;
    use VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private User $bruno;

    private User $carla;

    private User $caio;

    private OwnedDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->carla, $rh, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
        $this->domain = $this->companyDomain('acme.com.br', $this->ti);
    }

    private function companyDomain(string $domain, ?Sector $sector, bool $proven = true): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate([
            'user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'sector_id' => $sector?->id, 'domain' => $domain,
            'source' => 'radar', 'proof_token' => $proven ? $this->acme->domain_token : null, 'verified_at' => $proven ? now() : null,
        ]);
    }

    private function subdomains(): CompanySubdomains
    {
        return app(CompanySubdomains::class);
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

    private function up(string $name, string $ip = '203.0.113.10'): void
    {
        $this->dns->set($name, 'ips', [$ip]);
    }

    public function test_the_database_only_takes_names_inside_a_company_domain(): void
    {
        $insert = fn (OwnedDomain $domain, string $name) => DB::table('owned_subdomains')->insert([
            'owned_domain_id' => $domain->id, 'name' => $name, 'origin' => 'manual', 'status' => 'active', 'first_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $refused = function (callable $write): bool {
            try {
                DB::transaction($write);
            } catch (QueryException) {
                return true;
            }

            return false;
        };

        foreach (['acme.com.br', 'xacme.com.br', 'acme.com.br.evil.com', 'login.outra.com.br'] as $name) {
            $this->assertTrue($refused(fn () => $insert($this->domain, $name)), $name);
        }
        $this->assertTrue($refused(fn () => $insert($this->proveDomain(User::factory()->create()->id, 'pessoal.com.br'), 'a.pessoal.com.br')), 'domínio pessoal');
        $this->assertTrue($refused(fn () => DB::table('owned_subdomains')->insert(['owned_domain_id' => $this->domain->id, 'name' => 'a.acme.com.br', 'origin' => 'manual', 'status' => 'qualquer', 'first_seen_at' => now()])), 'status');

        $insert($this->domain, 'login.acme.com.br');
        $this->assertTrue($refused(fn () => DB::table('owned_subdomains')->update(['name' => 'login.evil.com'])), 'trocar o nome para fora');

        // Some com o domínio.
        $this->domain->delete();
        $this->assertSame(0, OwnedSubdomain::query()->count());
    }

    public function test_the_dns_check_follows_the_rules_of_the_passive_recon(): void
    {
        $check = fn (string $name) => app(SubdomainCheck::class)->check('acme.com.br', $name);

        $this->up('login.acme.com.br');
        $this->assertSame([true, false, null], array_values(array_slice($check('login.acme.com.br'), 0, 3)));

        // CNAME dentro da zona: segue até o IP, continua dentro.
        $this->dns->set('www.acme.com.br', 'cname', ['web.acme.com.br']);
        $this->up('web.acme.com.br');
        $this->assertSame([true, false, null], array_values(array_slice($check('www.acme.com.br'), 0, 3)));

        // CNAME para um terceiro (a loja), delegação com NS de fora, zona filha de fora acima do nome.
        $this->dns->set('loja.acme.com.br', 'cname', ['shops.myshopify.com']);
        $this->up('shops.myshopify.com');
        $loja = $check('loja.acme.com.br');
        $this->assertSame([true, true, 'cname_fora'], [$loja['resolves'], $loja['outside'], $loja['reason']]);
        $this->assertSame(['shops.myshopify.com'], $loja['facts']['cname']);

        $this->dns->set('app.acme.com.br', 'ns', ['ns1.agencia.com']);
        $this->up('app.acme.com.br');
        $this->assertSame('delegado', $check('app.acme.com.br')['reason']);
        $this->dns->set('own.acme.com.br', 'ns', ['ns1.acme.com.br']);
        $this->up('own.acme.com.br');
        $this->assertFalse($check('own.acme.com.br')['outside'], 'delegação com NS do próprio domínio é da empresa');

        $this->dns->set('filha.acme.com.br', 'ns', ['ns.terceiro.net']);
        $this->up('x.filha.acme.com.br');
        $this->assertSame('zona_filha', $check('x.filha.acme.com.br')['reason']);
        $this->up('a.b.c.d.e.acme.com.br');
        $this->assertSame('zona_desconhecida', $check('a.b.c.d.e.acme.com.br')['reason']);

        // Sem IP, laço, e o DNS mudo ("não sei", nunca "não existe").
        $this->assertSame([false, 'sem_ip'], [$check('nada.acme.com.br')['resolves'], $check('nada.acme.com.br')['reason']]);
        $this->dns->set('l1.acme.com.br', 'cname', ['l2.acme.com.br'])->set('l2.acme.com.br', 'cname', ['l1.acme.com.br']);
        $this->assertFalse($check('l1.acme.com.br')['resolves']);
        $this->dns->failing['mudo.acme.com.br'] = 5;
        $this->assertSame([null, 'dns_mudo'], [$check('mudo.acme.com.br')['resolves'], $check('mudo.acme.com.br')['reason']]);
    }

    public function test_whoever_configures_the_domain_adds_a_subdomain_that_resolves(): void
    {
        $this->up('login.acme.com.br');
        $this->up('www.acme.com.br');

        $sub = $this->subdomains()->add($this->bruno, $this->domain, 'https://Login.Acme.com.br/entrar');
        $this->assertSame(['login.acme.com.br', 'manual', 'active', $this->bruno->id], [$sub->name, $sub->origin, $sub->status, $sub->activated_by]);
        $this->assertFalse($sub->isOutside());
        // O www. é um subdomínio como outro qualquer (o domínio tira, o subdomínio não).
        $this->assertSame('www.acme.com.br', $this->subdomains()->add($this->ana, $this->domain, 'www.acme.com.br')->name);
        $this->assertSame(1, OwnedDomain::query()->count(), 'não conta nos 15 domínios');
        $this->assertSame('Subdomínio cadastrado: login.acme.com.br', app(CompanyAudit::class)->describe(CompanyAuditEntry::query()->where('action', 'subdomain.added')->oldest('id')->first()));

        // O membro só vê; o responsável de outro setor nem vê o domínio.
        $this->assertStringContainsString('Só a admin e quem responde pelo setor', $this->refused(fn () => $this->subdomains()->add($this->caio, $this->domain, 'a.acme.com.br')));
        $this->assertSame('Domínio não encontrado.', $this->refused(fn () => $this->subdomains()->add($this->carla, $this->domain, 'a.acme.com.br')));
        $this->assertTrue(OwnedSubdomain::query()->visibleTo($this->caio)->exists());
        $this->assertFalse(OwnedSubdomain::query()->visibleTo($this->carla)->exists());
    }

    public function test_the_name_must_be_a_live_subdomain_of_the_proven_domain(): void
    {
        $add = fn (string $name, ?OwnedDomain $domain = null) => $this->refused(fn () => $this->subdomains()->add($this->ana, $domain ?? $this->domain, $name));

        $this->assertSame('O subdomínio precisa terminar em .acme.com.br.', $add('acme.com.br'));
        $this->assertSame('O subdomínio precisa terminar em .acme.com.br.', $add('xacme.com.br'));
        $this->assertSame('O subdomínio precisa terminar em .acme.com.br.', $add('acme.com.br.evil.com'));
        $this->assertStringStartsWith('Informe um nome válido', $add('*.acme.com.br'));
        $this->assertStringStartsWith('Informe um nome válido', $add('login.acme.com.br:8443'));
        $this->assertSame('nada.acme.com.br não resolve no DNS (sem endereço). Cadastre um subdomínio que já está no ar.', $add('nada.acme.com.br'));
        $this->dns->failing['mudo.acme.com.br'] = 5;
        $this->assertStringStartsWith('Não conseguimos consultar o DNS', $add('mudo.acme.com.br'));

        // Domínio ainda sem a posse, e o domínio da conta pessoal (P4).
        $this->up('a.pendente.com.br');
        $this->assertStringStartsWith('Comprove a posse de pendente.com.br', $add('a.pendente.com.br', $this->companyDomain('pendente.com.br', null, proven: false)));
        $this->assertSame('Subdomínios são do plano empresarial.', $add('a.pessoal.com.br', $this->proveDomain(User::factory()->create()->id, 'pessoal.com.br')));
        $this->assertSame(0, OwnedSubdomain::query()->count());
    }

    public function test_a_third_party_subdomain_is_active_but_marked_outside(): void
    {
        $this->dns->set('loja.acme.com.br', 'cname', ['shops.myshopify.com']);
        $this->up('shops.myshopify.com');

        $sub = $this->subdomains()->add($this->ana, $this->domain, 'loja.acme.com.br');

        $this->assertSame(['active', 'cname_fora'], [$sub->status, $sub->reason]);
        $this->assertTrue($sub->isOutside());
    }

    public function test_the_ceiling_of_20_per_domain_and_the_limit_emails(): void
    {
        $this->acme->forceFill(['limit_subdomains' => 5])->save();
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $label) {
            $this->up("{$label}.acme.com.br");
        }

        foreach (['a', 'b', 'c'] as $label) {
            $this->subdomains()->add($this->ana, $this->domain, "{$label}.acme.com.br");
        }
        $this->assertSame(0, EmailMessage::query()->where('kind', 'empresa-limite')->count());

        // 4 de 5 = 80%: a admin e quem responde pelo setor do domínio; o mesmo nome de novo é recusado.
        $this->subdomains()->add($this->ana, $this->domain, 'd.acme.com.br');
        $this->assertSame(['ana@acme.com', $this->bruno->email], EmailMessage::query()->where('kind', 'empresa-limite')->orderBy('id')->pluck('to_address')->all());
        $this->assertSame('d.acme.com.br já está entre os subdomínios de acme.com.br.', $this->refused(fn () => $this->subdomains()->add($this->ana, $this->domain, 'd.acme.com.br')));

        $this->subdomains()->add($this->ana, $this->domain, 'e.acme.com.br');
        $this->assertSame('acme.com.br chegou ao limite de 5 subdomínios. Tire um para cadastrar outro.', $this->refused(fn () => $this->subdomains()->add($this->ana, $this->domain, 'f.acme.com.br')));

        // Tirar um libera a vaga (fica `ignored`, a descoberta não o traz de volta), e cadastrar de novo reativa.
        $e = OwnedSubdomain::query()->where('name', 'e.acme.com.br')->sole();
        $this->subdomains()->remove($this->bruno, $e);
        $this->assertSame('ignored', $e->refresh()->status);
        $this->assertSame([80], DB::table('company_limit_notices')->where('limit', 'subdomains')->pluck('level')->all());
        $this->subdomains()->add($this->ana, $this->domain, 'f.acme.com.br');
        $this->subdomains()->remove($this->ana, OwnedSubdomain::query()->where('name', 'f.acme.com.br')->sole());
        $this->assertSame('active', $this->subdomains()->add($this->ana, $this->domain, 'e.acme.com.br')->status);
        $this->assertSame('manual', $e->refresh()->origin);
        $this->assertStringContainsString('Só a admin', $this->refused(fn () => $this->subdomains()->remove($this->caio, $e)));
    }

    public function test_a_name_under_a_company_domain_is_a_subdomain_not_a_new_domain(): void
    {
        $ownership = app(DomainOwnership::class);
        $owner = DomainOwner::company($this->acme->id, $this->ana->id, $this->ti->id);

        // Na tela de domínios (E3a3): cadastrar x.acme.com.br como domínio é recusado (decisão do Designer).
        $this->assertSame('Já coberto por acme.com.br. Cadastre como subdomínio.', $this->refused(fn () => $ownership->claimNew($owner, 'loja.acme.com.br', 'radar')));
        $this->assertSame('acme.com.br já está entre os domínios da empresa.', $this->refused(fn () => $ownership->claimNew($owner, 'acme.com.br', 'radar')));

        // Pelos serviços (Radar, Notícias), o nome de baixo usa a linha de cima, sem gastar um dos 15.
        $this->assertTrue($ownership->claim($owner, 'loja.acme.com.br', 'radar')->is($this->domain));
        $this->assertSame(1, OwnedDomain::query()->where('company_id', $this->acme->id)->count());
        $this->assertSame('outra.com.br', $ownership->claimNew($owner, 'outra.com.br', 'radar')->domain);
    }
}
