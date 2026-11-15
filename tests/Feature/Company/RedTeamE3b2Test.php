<?php

namespace Tests\Feature\Company;

use App\Models\OwnedDomain;
use App\Models\OwnedSubdomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E3b2, parte 1 (2af6852). K1: as rotas `company.subdomains.*` só têm o `enterprise`: a conta pessoal (e a de
 * outra empresa) passa pelo limite de requisições e pelo binding antes do 404 da FormRequest, e acima do limite leva 429,
 * que conta que a rota existe. Na pilha, depois da E3a3, as rotas ganham o `company.account` (404 antes do limite).
 */
class RedTeamE3b2Test extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    public function test_rt_k1_the_personal_account_gets_a_404_never_a_429(): void
    {
        Queue::fake();
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        [$acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $owned = OwnedDomain::query()->forceCreate(['user_id' => $ana->id, 'company_id' => $acme->id, 'domain' => 'acme.com.br', 'source' => 'company',
            'proof_token' => $acme->domain_token, 'verified_at' => now()]);
        $sub = OwnedSubdomain::query()->forceCreate(['owned_domain_id' => $owned->id, 'name' => 'login.acme.com.br', 'origin' => 'manual', 'status' => 'active', 'first_seen_at' => now()]);
        $this->actingAs(User::factory()->create());

        // No 2af6852, a 31ª dava 429 (o limite de 30 por minuto vinha antes do 404).
        foreach (range(1, 32) as $i) {
            $this->post(route('company.subdomains.store', $owned), ['nome' => "x{$i}.acme.com.br"])->assertNotFound();
            $this->delete(route('company.subdomains.remove', $sub))->assertNotFound();
        }
        $this->assertSame('active', $sub->refresh()->status);
    }
}
