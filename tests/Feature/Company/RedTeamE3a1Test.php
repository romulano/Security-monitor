<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Services\Domains\DomainTokens;
use App\Services\Pentest\PentestEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E3a1. `rt_*`: passava no 058b062 e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * X1 (a decisão a avaliar): no `check()` da conta, o token "extra" de **outra conta** ainda prova, e o `prove()` grava
 * esse token no `proof_token` da linha pessoal. Daí em diante ele está em `tokens()` da conta e prova **qualquer** nome
 * com aquele TXT: uma cópia com o token da vítima vira a posse de todos os domínios da vítima. Nenhum caminho do código
 * grava hoje o token de outra conta numa cópia (todo `verify_token` nasce do `txtToken` do dono ou da prova dele), então
 * não é explorável agora; mas o `claim()` já recusa esse token, e o `check()` deixava passar: fica igual ao `claim()`.
 */
class RedTeamE3a1Test extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
    }

    private function ownership(): DomainOwnership
    {
        return app(DomainOwnership::class);
    }

    private function txt(string $name, string $token): void
    {
        $this->dns->set($name, 'txt', [DomainOwnership::PREFIX.$token]);
    }

    public function test_rt_x1_a_copy_with_another_accounts_token_never_proves_nor_spreads(): void
    {
        // A vítima, conta pessoal, com o TXT dela publicado nos domínios dela (público no DNS).
        $vitima = User::factory()->create();
        $token = $this->ownership()->token($vitima);
        $this->txt('vitima.com.br', $token);
        $this->txt('loja-da-vitima.com.br', $token);

        // Uma cópia da atacante com o token da vítima (um caminho que gravasse o token alheio).
        $atacante = User::factory()->create();
        $forged = MonitoredDomain::query()->create(['user_id' => $atacante->id, 'domain' => 'vitima.com.br', 'verify_token' => $token]);

        // No 058b062: provava vitima.com.br e o token entrava no proof_token da atacante.
        $this->assertFalse($this->ownership()->verifyRow($forged, 'radar'));
        $this->assertNotContains($token, $this->ownership()->tokens(DomainOwner::account($atacante->id)));

        // ...e com ele, qualquer outro domínio da vítima, sem cópia forjada nenhuma.
        $this->assertNull($this->ownership()->verify(DomainOwner::account($atacante->id), 'loja-da-vitima.com.br', 'radar'));

        // A vítima continua provando os dela.
        $this->assertNotNull($this->ownership()->verify(DomainOwner::account($vitima->id), 'loja-da-vitima.com.br', 'radar'));
    }

    public function test_evidencia_an_rootless_copy_never_gives_the_company_token_to_the_member(): void
    {
        [$acme] = $this->activeCompany('Acme', 'ana@acme.com');
        $caio = $this->companyAccount($acme, CompanyRole::SectorManager);
        $watch = $this->watchOf($caio, $acme);
        $row = $watch->domain;
        $row->forceFill(['domain' => 'acme.com.br', 'verify_token' => $acme->domain_token])->save();
        $this->txt('acme.com.br', $acme->domain_token);
        $this->txt('caio.com.br', $acme->domain_token);
        $this->assertTrue($this->ownership()->verifyRow($row->refresh(), 'radar'));

        // Uma cópia sem raiz da mesma conta (a do webhook, ou uma que perdesse o item) com o token da empresa: o dono
        // dela é a conta, nunca a empresa.
        $orphan = MonitoredDomain::query()->create(['user_id' => $caio->id, 'domain' => 'acme.com.br', 'verify_token' => $acme->domain_token]);
        $this->assertFalse($this->ownership()->ownerOf($orphan)->isCompany());

        $this->assertFalse($this->ownership()->verifyRow($orphan, 'radar'));
        $this->assertNotContains($acme->domain_token, $this->ownership()->tokens(DomainOwner::account($caio->id)));
        $this->assertNull($this->ownership()->verify(DomainOwner::account($caio->id), 'caio.com.br', 'radar'));
        $this->assertNull(DB::table('owned_domains')->where('user_id', $caio->id)->whereNull('company_id')->where('verified_at', '!=', null)->value('id'));
    }

    public function test_evidencia_the_stack_and_the_monitor_stay_on_the_account(): void
    {
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        OwnedDomain::query()->forceCreate(['user_id' => $ana->id, 'company_id' => $acme->id, 'domain' => 'acme.com.br', 'source' => 'radar',
            'proof_token' => $acme->domain_token, 'verified_at' => now()]);

        // A stack (passo 1 e a prova exata) nunca lê a posse da empresa, nem para a admin dela (o webhook passa à empresa na E3a2).
        $this->assertSame([], app(PentestEnrollment::class)->provenDomains($ana)->pluck('domain')->all());
        $this->assertNull($this->ownership()->exactProof(DomainOwner::account($ana->id), 'acme.com.br'));
        // O TXT que a tela da stack mostraria é o da conta, nunca o da empresa.
        $this->assertNotSame($acme->domain_token, $this->ownership()->txtToken(DomainOwner::account($ana->id), 'acme.com.br'));

        // E a prova achada por fora pela stack com o token da empresa não entra.
        $this->txt('acme.com.br', $acme->domain_token);
        $this->assertFalse($this->ownership()->record($ana->id, 'acme.com.br', $acme->domain_token, 'tests')->isVerified());
    }

    public function test_evidencia_every_token_store_counts_as_used(): void
    {
        $registry = app(DomainTokens::class);
        $admin = User::factory()->create(['is_admin' => true]);
        DB::table('security_sites')->insert(['user_id' => $admin->id, 'host' => 'site.com.br', 'enabled' => true, 'interval_minutes' => 360,
            'consent_at' => now(), 'consent_ip' => '203.0.113.9', 'verify_token' => 'do-monitor', 'ownership' => 'txt', 'next_check_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('owned_domains')->insert(['user_id' => $admin->id, 'domain' => 'legado.com.br', 'source' => 'radar', 'tokens' => json_encode(['legado-1']),
            'proof_token' => 'prova-1', 'created_at' => now(), 'updated_at' => now()]);
        MonitoredDomain::query()->create(['user_id' => $admin->id, 'domain' => 'copia.com.br', 'verify_token' => 'da-copia']);

        foreach (['do-monitor', 'legado-1', 'prova-1', 'da-copia', $this->ownership()->token($admin)] as $token) {
            $this->assertTrue($registry->used($token), $token);
        }
        $this->assertFalse($registry->used('ninguem-usa'));
        // O legado da própria conta não é "de outro dono"; o de outra conta é.
        $this->assertFalse($registry->ownedByOther('legado-1', $admin->id));
        $this->assertTrue($registry->ownedByOther('legado-1', User::factory()->create()->id));
    }
}
