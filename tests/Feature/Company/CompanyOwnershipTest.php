<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\CompanyHandover;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Services\Domains\DomainTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\Support\OwnershipHelpers;
use Tests\TestCase;

/**
 * E3a1 (DESENHO-5.3 §2.1, §2.3, §2.8): a posse do domínio de um item da empresa é da empresa, provada só com o token da
 * empresa; o token de um dono nunca entra no conjunto de outro; a poda passa a posse para a admin.
 */
class CompanyOwnershipTest extends TestCase
{
    use OwnershipHelpers;
    use RefreshDatabase;
    use VisibilityFixtures;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
    }

    private function ownership(): DomainOwnership
    {
        return app(DomainOwnership::class);
    }

    /** Um Radar da empresa sobre o domínio, com a cópia que mostra o token da empresa (como a entrevista grava). */
    private function companyRadar(User $owner, Company $company, string $domain): MonitoredDomain
    {
        $watch = $this->watchOf($owner, $company);
        $watch->domain->forceFill(['domain' => $domain, 'verify_token' => $company->domain_token])->save();

        return $watch->domain->refresh();
    }

    private function txt(string $name, string $token): void
    {
        $this->dns->set($name, 'txt', [DomainOwnership::PREFIX.$token]);
    }

    public function test_the_company_proves_with_its_token_and_whoever_registered_does_not_matter(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $caio = $this->companyAccount($acme, CompanyRole::SectorManager);
        $row = $this->companyRadar($caio, $acme, 'acme.com.br');

        // O token da conta de quem cadastrou não prova nada para a empresa.
        $this->txt('acme.com.br', $this->ownership()->token($caio));
        $this->assertFalse($this->ownership()->verifyRow($row, 'radar'));

        $this->txt('acme.com.br', $acme->domain_token);
        $this->assertTrue($this->ownership()->verifyRow($row, 'radar'));

        $owned = OwnedDomain::query()->where('domain', 'acme.com.br')->sole();
        $this->assertSame([$acme->id, $caio->id, $acme->domain_token, []], [$owned->company_id, $owned->user_id, $owned->proof_token, $owned->tokens]);
        $this->assertNull(DB::table('owned_domains')->where('user_id', $caio->id)->whereNull('company_id')->value('id'), 'nada na conta de quem cadastrou');

        // Outra conta da empresa, num alerta de Notícias do mesmo domínio: chega comprovado, sem TXT novo.
        $profile = $this->profileOf($ana, $acme);
        $news = $profile->domains()->create(['domain' => 'loja.acme.com.br', 'verify_token' => $acme->domain_token]);
        $this->dns->set('loja.acme.com.br', 'txt', []);
        $this->assertTrue($this->ownership()->verifyRow($news, 'news'));
        $this->assertSame($acme->domain_token, $this->ownership()->txtToken(DomainOwner::company($acme->id, $ana->id), 'outro.com.br'));
    }

    public function test_the_tokens_never_cross_between_an_account_and_a_company(): void
    {
        [$acme] = $this->activeCompany('Acme', 'ana@acme.com');
        [$beta] = $this->activeCompany('Beta', 'paulo@beta.com');
        $pedro = User::factory()->create();
        $mine = MonitoredDomain::query()->create(['user_id' => $pedro->id, 'domain' => 'pedro.com.br', 'verify_token' => $this->ownership()->token($pedro)]);

        // O token da Acme (público no DNS) no nome da conta pessoal: não prova para a conta.
        $this->txt('pedro.com.br', $acme->domain_token);
        $this->assertFalse($this->ownership()->verifyRow($mine, 'radar'));

        // Uma cópia da conta com o token da empresa (um caminho que gravasse o token alheio) não o leva para a conta.
        $forged = MonitoredDomain::query()->create(['user_id' => $pedro->id, 'domain' => 'forjado.com.br', 'verify_token' => $acme->domain_token]);
        $this->txt('forjado.com.br', $acme->domain_token);
        $this->assertFalse($this->ownership()->verifyRow($forged, 'radar'));
        $this->assertNotContains($acme->domain_token, $this->ownership()->tokens(DomainOwner::account($pedro->id)));

        // A prova achada por fora (a da stack) com o token da empresa também não entra na conta.
        $this->assertFalse($this->ownership()->record($pedro->id, 'forjado.com.br', $acme->domain_token, 'tests')->isVerified());

        // Nem o token de outra conta, nem o de outra empresa provam para a Acme.
        $acmeRow = $this->companyRadar($this->companyAccount($acme), $acme, 'acme.com.br');
        $this->txt('acme.com.br', $this->ownership()->token($pedro));
        $this->assertFalse($this->ownership()->verifyRow($acmeRow, 'radar'));
        $this->txt('acme.com.br', $beta->domain_token);
        $this->assertFalse($this->ownership()->verifyRow($acmeRow, 'radar'));
        $this->assertSame([$acme->domain_token], $this->ownership()->tokens(DomainOwner::company($acme->id, $pedro->id)));
    }

    public function test_the_account_token_is_never_one_of_another_owner(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $pedro = User::factory()->create();
        // A prova mais antiga da conta com o token da empresa (gravado à mão): a conta não o adota como o dela.
        DB::table('owned_domains')->insert(['user_id' => $pedro->id, 'domain' => 'pedro.com.br', 'source' => 'radar', 'tokens' => '[]',
            'proof_token' => $acme->domain_token, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $token = $this->ownership()->token($pedro);

        $this->assertNotSame($acme->domain_token, $token);
        $this->assertFalse(app(DomainTokens::class)->ownedByOther($token, $pedro->id));
        $this->assertTrue(app(DomainTokens::class)->ownedByOther($acme->domain_token, $pedro->id));
        $this->assertTrue(app(DomainTokens::class)->ownedByOther($token, $ana->id));

        // Todo lugar onde um token mora conta como usado, e o novo nunca é um deles.
        foreach ([$token, $acme->domain_token, $this->watchOf($ana)->domain->verify_token] as $used) {
            $this->assertTrue(app(DomainTokens::class)->used($used));
        }
        $this->assertFalse(app(DomainTokens::class)->used(app(DomainTokens::class)->fresh()));

        // O token não sai no JSON da conta (Red Team da E1a).
        $this->assertArrayNotHasKey('domain_token', $pedro->refresh()->toArray());
    }

    public function test_the_proven_list_follows_the_visibility(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $ti = $this->companySector($acme, 'TI');
        $rh = $this->companySector($acme, 'RH');
        $caio = $this->companyAccount($acme);
        $this->joinSector($caio, $ti);
        $pedro = User::factory()->create();

        $own = fn (string $domain, ?int $sector) => OwnedDomain::query()->forceCreate([
            'user_id' => $ana->id, 'company_id' => $acme->id, 'sector_id' => $sector, 'domain' => $domain, 'source' => 'radar',
            'proof_token' => $acme->domain_token, 'verified_at' => now(),
        ]);
        $own('acme.com.br', null);
        $own('ti.acme.com.br', $ti->id);
        $own('rh.acme.com.br', $rh->id);
        $this->proveDomain($pedro->id, 'pedro.com.br');
        // O que uma conta de empresa comprovou como pessoal, antes da E3a1, não aparece para ninguém da empresa.
        $this->proveDomain($caio->id, 'antigo.com.br');

        $this->assertSame(['acme.com.br', 'ti.acme.com.br', 'rh.acme.com.br'], $this->ownership()->proven($ana)->pluck('domain')->all());
        $this->assertSame(['acme.com.br', 'ti.acme.com.br'], $this->ownership()->proven($caio)->pluck('domain')->all());
        $this->assertSame(['pedro.com.br'], $this->ownership()->proven($pedro)->pluck('domain')->all());
        // O "comprovado em Notícias" da empresa não diz o nome do alerta (pode ser de outro setor).
        $news = $own('noticias.com.br', null);
        $news->forceFill(['source' => 'news'])->save();
        $this->assertSame('em Notícias', $this->ownership()->origin($news));
    }

    public function test_losing_the_txt_stops_the_company_items_and_the_purge_keeps_the_proof(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $bia = $this->companyAccount($acme, CompanyRole::SectorManager, ['deactivated_at' => now()->subDays(31)]);
        $row = $this->companyRadar($bia, $acme, 'acme.com.br');
        $this->txt('acme.com.br', $acme->domain_token);
        $this->assertTrue($this->ownership()->verifyRow($row, 'radar'));

        // A poda passa a posse e a cópia para a admin; a prova continua (é da empresa).
        $this->artisan('secmon:company-purge')->assertSuccessful();
        $this->assertModelMissing($bia);
        $owned = OwnedDomain::query()->where('domain', 'acme.com.br')->sole();
        $this->assertSame([$ana->id, $acme->id], [$owned->user_id, $owned->company_id]);
        $this->ownership()->apply($row->refresh());
        $this->assertTrue($row->refresh()->isVerified(), 'a cópia continua comprovada depois da poda');

        // O TXT da empresa some por 7 dias: a posse cai e a cópia da empresa para.
        $this->dns->set('acme.com.br', 'txt', []);
        $this->ownership()->recheck($owned);
        $this->travel(8)->days();
        $this->ownership()->recheck($owned->refresh());
        $this->assertNull($owned->refresh()->verified_at);
        $this->assertFalse($row->refresh()->isVerified());
    }

    public function test_the_handover_moves_only_the_company_proof_of_the_gone_account(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $caio = $this->companyAccount($acme, state: ['deactivated_at' => now()->subDays(31)]);
        $company = OwnedDomain::query()->forceCreate(['user_id' => $caio->id, 'company_id' => $acme->id, 'domain' => 'acme.com.br', 'source' => 'radar']);
        $leftover = $this->proveDomain($caio->id, 'antigo.com.br');

        $this->assertSame(1, app(CompanyHandover::class)->toAdmin($this->memberRow($caio)));
        $this->assertSame($ana->id, $company->fresh()->user_id);
        $this->assertSame($caio->id, $leftover->fresh()->user_id, 'a linha pessoal segue a conta');
    }
}
