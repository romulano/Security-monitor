<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\CompanyStamp;
use App\Services\Company\ItemSectors;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E3a2. `rt_*`: passava no 19607ce e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * - Z1: o carimbo leva para a empresa **toda** posse pessoal de uma conta de empresa, também a que nada tem com a
 *   empresa (o blog pessoal de quem trabalha lá): a empresa toda passa a ver o nome, e ele gasta o limite de 15 sem
 *   passar pelo `ensureRoom`. Só passa o nome que um alerta ou Radar da empresa usa (ele ou um subdomínio dele).
 * - Z2: trocar o setor do item só confere a linha do **próprio nome**; o item coberto pelo domínio de cima do setor
 *   antigo vai para o setor novo e perde a prova calado (o Radar para), quando a P1 manda recusar.
 * - Z3: a busca pelos domínios de cima cadastra o pai que achou com o setor do item: o responsável do TI que comprova
 *   loja.acme.com.br registra acme.com.br no TI, e o RH não usa mais o domínio principal da empresa (nem o vê). O pai
 *   cadastrado pela busca nasce sem setor (serve a todos, P1).
 */
class RedTeamE3a2Test extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $bruno;

    private User $carla;

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
        $this->carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->carla, $this->rh, manager: true);
    }

    private function ownership(): DomainOwnership
    {
        return app(DomainOwnership::class);
    }

    private function txt(string $name): void
    {
        $this->dns->set($name, 'txt', [DomainOwnership::PREFIX.$this->acme->domain_token]);
    }

    public function test_rt_z1_the_stamp_never_takes_a_personal_domain_the_company_does_not_use(): void
    {
        // Antes da E3a1, o Bruno comprovou como pessoal o blog dele e o domínio de um Radar da empresa.
        $old = fn (string $domain) => OwnedDomain::query()->forceCreate(['user_id' => $this->bruno->id, 'domain' => $domain, 'source' => 'radar',
            'tokens' => ['tokantigo'], 'proof_token' => 'tokantigo', 'verified_at' => now()->subMonth()]);
        $blog = $old('blog-do-bruno.com.br');
        $portal = $old('portal.com.br');
        $watch = $this->watchOf($this->bruno, $this->acme);
        $watch->domain->forceFill(['domain' => 'app.portal.com.br', 'verify_token' => 'tokantigo'])->save();

        app(CompanyStamp::class)->domains();

        // No 19607ce, o blog virava domínio da empresa, visto pela Ana e pela Carla.
        $this->assertNull($blog->refresh()->company_id);
        $this->assertNotContains('blog-do-bruno.com.br', $this->ownership()->proven($this->carla)->pluck('domain')->all());
        // O domínio de cima do que o Radar da empresa usa passa, como antes.
        $this->assertSame($this->acme->id, $portal->refresh()->company_id);
    }

    public function test_rt_z2_an_item_covered_by_a_parent_of_its_sector_does_not_move_away_silently(): void
    {
        OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'sector_id' => $this->ti->id,
            'domain' => 'acme.com.br', 'source' => 'radar', 'proof_token' => $this->acme->domain_token, 'verified_at' => now()]);
        // O nome do item já está na empresa sem setor (cadastrado antes, sem TXT próprio): a prova vem do domínio de cima.
        OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'loja.acme.com.br', 'source' => 'radar']);
        $watch = app(RadarInterview::class)->saveDomain($this->ana, null, null, 'loja.acme.com.br', $this->ti->id);
        $this->assertTrue($watch->domain->isVerified(), 'coberto pelo domínio de cima do TI');

        // No 19607ce, ia para o RH e o Radar perdia a prova sem aviso.
        try {
            app(ItemSectors::class)->move($this->ana, $watch, $this->rh->id, 'Radar', 'loja.acme.com.br');
            $this->fail('Moveu o Radar para um setor que o domínio dele não serve.');
        } catch (ActionRefused $e) {
            $this->assertStringNotContainsString('TI', $e->getMessage());
        }

        $this->assertSame($this->ti->id, $watch->refresh()->sector_id);
        $this->assertTrue($watch->domain->isVerified());
    }

    public function test_rt_z3_the_parent_found_by_the_walk_is_registered_without_sector(): void
    {
        $this->txt('acme.com.br');
        $this->dns->set('loja.acme.com.br', 'txt', []);
        $radar = app(RadarInterview::class);
        $loja = $radar->saveDomain($this->bruno, null, null, 'loja.acme.com.br', $this->ti->id);
        $this->assertTrue($this->ownership()->verifyRow($loja->domain, 'radar'));

        // No 19607ce, acme.com.br nascia no TI, e o Radar do RH sobre o domínio principal era recusado.
        $this->assertNull(OwnedDomain::query()->where('domain', 'acme.com.br')->value('sector_id'));
        $this->assertSame('loja.acme.com.br', OwnedDomain::query()->where('sector_id', $this->ti->id)->value('domain'), 'o nome do item fica no setor dele');
        $rhWatch = $radar->saveDomain($this->carla, null, null, 'acme.com.br', $this->rh->id);
        $this->assertTrue($rhWatch->domain->isVerified());
    }

    public function test_evidencia_the_sector_rule_never_names_the_sector_and_the_limit_holds(): void
    {
        OwnedDomain::query()->forceCreate(['user_id' => $this->bruno->id, 'company_id' => $this->acme->id, 'sector_id' => $this->ti->id,
            'domain' => 'ti-secreto.acme.com.br', 'source' => 'radar', 'proof_token' => $this->acme->domain_token, 'verified_at' => now()]);

        // O RH pedindo o nome do TI: a recusa não diz o setor, e nada é criado.
        try {
            $this->ownership()->claim(DomainOwner::company($this->acme->id, $this->carla->id, $this->rh->id), 'ti-secreto.acme.com.br', 'radar');
            $this->fail('Usou o domínio de outro setor.');
        } catch (ActionRefused $e) {
            $this->assertSame(DomainOwnership::OTHER_SECTOR, $e->getMessage());
        }
        $this->assertSame(1, OwnedDomain::query()->where('company_id', $this->acme->id)->count());
        $this->assertNull($this->ownership()->proof(DomainOwner::company($this->acme->id, $this->carla->id, $this->rh->id), 'app.ti-secreto.acme.com.br'));

        // O limite com o nome repetido (claim do mesmo nome) não conta duas vezes; o 16º é recusado.
        $this->acme->forceFill(['limit_domains' => 2])->save();
        $owner = DomainOwner::company($this->acme->id, $this->ana->id);
        $this->ownership()->claim($owner, 'dois.com.br', 'radar');
        $this->ownership()->claim($owner, 'dois.com.br', 'radar');
        try {
            $this->ownership()->claim($owner, 'tres.com.br', 'radar');
            $this->fail('Passou do limite.');
        } catch (ActionRefused) {
            $this->assertSame(2, OwnedDomain::query()->where('company_id', $this->acme->id)->count());
        }
    }
}
