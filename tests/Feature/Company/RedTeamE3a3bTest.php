<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyAuditEntry;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\CompanyDomains;
use App\Services\Domains\DomainOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E3a3b (remover): só `evidencia_*`, o que resistiu no 175ae84 (e o H1, a contagem que exagerava, corrigido). */
class RedTeamE3a3bTest extends TestCase
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
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ana->forceFill(['password' => 'password'])->save();
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
    }

    private function domain(string $name, ?Sector $sector, ?int $company = null): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate(['user_id' => $this->ana->id, 'company_id' => $company ?? $this->acme->id, 'sector_id' => $sector?->id,
            'domain' => $name, 'source' => 'company', 'proof_token' => $this->acme->domain_token, 'verified_at' => now()]);
    }

    private function remove(User $who, string $value)
    {
        return $this->actingAs($who)->from(route('company.domains'))->delete(route('company.domains.remove'), ['dominio' => $value, 'reauth_password' => 'password']);
    }

    public function test_evidencia_removing_only_what_one_configures_and_only_what_the_proof_holds(): void
    {
        $open = $this->domain('acme.com.br', null);
        $rh = $this->domain('rh.acme.com.br', $this->rh);

        // O responsável do TI não remove o domínio sem setor nem o do RH; a mensagem é a do id que não existe.
        foreach ([$open->id, $rh->id, 999999] as $id) {
            $this->remove($this->bruno, "{$id}-0-0-0")->assertSessionHas('error', 'Escolha um domínio que você configura.');
        }
        // A contagem forjada com campo a mais não passa da validação.
        $this->remove($this->ana, "{$open->id}-0-0-0-0")->assertSessionHasErrors('dominio');
        $this->assertSame(2, OwnedDomain::query()->count());

        // Um Radar do TI em loja.acme.com.br, coberto pelo nome dele e pelo de cima: remover a linha dele não o derruba,
        // porque o domínio de cima sem setor ainda prova. H1: a contagem do diálogo também não o conta.
        $loja = $this->domain('loja.acme.com.br', $this->ti);
        $watch = $this->watchOf($this->bruno, $this->acme, $this->ti);
        $watch->domain->forceFill(['domain' => 'loja.acme.com.br', 'verify_token' => $this->acme->domain_token])->save();
        app(DomainOwnership::class)->apply($watch->domain->refresh());
        $this->assertTrue($watch->domain->refresh()->isVerified());
        $this->assertSame(['alertas' => 0, 'radares' => 0, 'webhooks' => 0], app(CompanyDomains::class)->dependents($loja));

        $this->remove($this->bruno, "{$loja->id}-0-0-0")->assertSessionHas('status');
        $this->assertTrue($watch->domain->refresh()->isVerified(), 'o domínio de cima sem setor continua provando');
        $this->assertSame(['setor' => 'TI', 'alertas' => 0, 'radares' => 0, 'webhooks' => 0], CompanyAuditEntry::query()->where('action', 'domain.removed')->sole()->data);
    }
}
