<?php

namespace Tests\Feature\Domains;

use App\Jobs\DiscoverDomainStack;
use App\Models\KeySite;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Services\Pentest\PentestEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\TestCase;

/**
 * A posse de domínio é uma só para a conta (DomainOwnership): comprovada num serviço, nenhum outro
 * pede de novo. Instalação self-hosted, de um dono só: todo domínio nasce já comprovado
 * (`verified_at` na criação), sem prova por registro TXT no DNS — não existe mais a tela "Crie este
 * registro TXT..." nem a espera por ele.
 */
class DomainOwnershipTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function ownership(): DomainOwnership
    {
        return app(DomainOwnership::class);
    }

    /** O domínio do alerta de notícias, pelo passo 5 da entrevista, com o aceite. */
    private function news(User $user, string $name): MonitoredDomain
    {
        $this->actingAs($user)->put(route('alerts.interview.update', 5), [
            'answers' => ['has_domain' => 'sim', 'domain' => $name], 'consent' => '1', 'acao' => 'ficar',
        ])->assertRedirect();

        return MonitoredDomain::query()->ofAlerts()->where('user_id', $user->id)->where('domain', $name)->sole();
    }

    /** O domínio de um Radar, pelos passos 1 (digitado) e 2 da entrevista dele. */
    private function radar(User $user, string $name): LookalikeWatch
    {
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => $name])->assertRedirect();
        $watch = LookalikeWatch::query()->where('user_id', $user->id)->whereHas('domain', fn ($q) => $q->where('domain', $name))->sole();
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 2, 'radar' => $watch->id]), ['empresa' => 'Acme', 'marcas' => ['Acme']])->assertRedirect();

        return $watch->refresh();
    }

    public function test_every_domain_is_born_verified_in_every_service(): void
    {
        $user = $this->client();

        $news = $this->news($user, 'acme.com.br');
        $this->assertTrue($news->isVerified());
        Queue::assertPushed(DiscoverDomainStack::class, fn ($job) => $job->domainId === $news->id);

        $watch = $this->radar($user, 'outra.com.br');
        $this->assertTrue($watch->domain->isVerified());

        $copy = app(PentestEnrollment::class)->add($user, null, 'nova.com.br', null)->domain;
        $this->assertTrue($copy->isVerified());
    }

    public function test_proven_in_news_is_proven_in_the_radar_and_in_the_tests_without_asking_again(): void
    {
        $user = $this->client();
        $news = $this->news($user, 'acme.com.br');
        $this->assertTrue($news->isVerified());

        // O passo 1 do Radar oferece reaproveitar o domínio já comprovado em Notícias.
        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 1]))
            ->assertOk()
            ->assertSee('acme.com.br');

        $watch = $this->radar($user, 'acme.com.br');
        $this->assertTrue($watch->domain->isVerified());

        $copy = app(PentestEnrollment::class)->add($user, null, 'acme.com.br', null)->domain;
        $this->assertTrue($copy->isVerified(), 'o nome digitado nos testes também chega comprovado');
        $this->assertSame(['proof' => 'news', 'verified' => true], json_decode(DB::table('pentest_audit')->value('data'), true));
        // Uma linha só em owned_domains para o nome: News e Radar reaproveitam, não duplicam.
        $this->assertSame(1, OwnedDomain::query()->where('user_id', $user->id)->where('domain', 'acme.com.br')->count());
    }

    public function test_proven_in_the_tests_is_proven_in_news_and_in_the_radar(): void
    {
        $user = $this->client();
        $pentest = app(PentestEnrollment::class)->add($user, null, 'nova.com.br', null);
        $this->assertTrue($pentest->domain->isVerified());

        $this->assertTrue($this->radar($user, 'nova.com.br')->domain->isVerified());
        $this->assertTrue($this->news($user, 'nova.com.br')->isVerified());
        $this->assertSame('tests', OwnedDomain::query()->where('domain', 'nova.com.br')->value('source'));
    }

    public function test_the_proven_domain_covers_its_subdomains_for_display_but_exact_proof_requires_the_exact_name(): void
    {
        $user = $this->client();
        $this->news($user, 'empresa.com.br');

        // A conta já comprovou empresa.com.br: o passo 1 do Radar oferece reaproveitar.
        $proven = $this->ownership()->proven($user);
        $this->assertTrue($proven->contains('domain', 'empresa.com.br'));

        // A prova "de cima" (selfAndParents) cobre um subdomínio nunca cadastrado por si; exactProof, não
        // (nenhuma linha própria existe ainda para o subdomínio).
        $owner = DomainOwner::of($user->id);
        $this->assertNotNull($this->ownership()->proof($owner, 'loja.empresa.com.br'));
        $this->assertNull($this->ownership()->exactProof($owner, 'loja.empresa.com.br'));

        // Cadastrando o subdomínio em qualquer serviço (aqui, o Radar), ele nasce comprovado por conta
        // própria (instalação de um dono só) e passa a ter linha e exactProof próprios.
        $watch = $this->radar($user, 'loja.empresa.com.br');
        $this->assertTrue($watch->domain->isVerified());
        $this->assertNotNull($this->ownership()->exactProof($owner, 'loja.empresa.com.br'));

        // Os testes de segurança pedem o nome exato (regra própria, mais forte): a cópia nasce
        // comprovada do mesmo jeito, e o mapeamento (ownedProof) guarda achados no registro exato do nome.
        $copy = app(PentestEnrollment::class)->add($user, null, 'loja.empresa.com.br', null);
        $this->assertTrue($copy->domain->isVerified());
        $this->assertNotNull($copy->ownedProof());
        $this->assertSame('loja.empresa.com.br', $copy->ownedProof()->domain);
    }

    public function test_each_account_has_its_own_proof_even_for_the_same_domain_name(): void
    {
        $ana = $this->client();
        $bia = $this->client();

        $this->news($ana, 'novo.com.br');
        $this->news($bia, 'novo.com.br');

        $this->assertSame(1, OwnedDomain::query()->where('user_id', $ana->id)->where('domain', 'novo.com.br')->count());
        $this->assertSame(1, OwnedDomain::query()->where('user_id', $bia->id)->where('domain', 'novo.com.br')->count());
        $this->assertTrue($this->ownership()->proven($ana)->contains('domain', 'novo.com.br'));
        $this->assertTrue($this->ownership()->proven($bia)->contains('domain', 'novo.com.br'));
    }

    public function test_the_domain_leaves_keys_only_when_the_last_service_lets_it_go(): void
    {
        $user = User::factory()->create();
        $watch = $this->radar($user, 'loja.com.br');
        $news = $this->news($user, 'loja.com.br');
        $owned = OwnedDomain::query()->where('user_id', $user->id)->where('domain', 'loja.com.br')->sole();
        KeySite::query()->forceCreate(['owned_domain_id' => $owned->id, 'status' => 'active']);

        $this->actingAs($user)->delete(route('alerts.lookalikes.destroy', ['radar' => $watch->id]))->assertRedirect();
        $this->assertTrue($this->ownership()->proven($user)->contains('domain', 'loja.com.br'), 'o alerta de notícias ainda usa o nome');

        $news->delete();
        $this->assertFalse($this->ownership()->proven($user)->contains('domain', 'loja.com.br'));
        $this->assertSame(0, KeySite::query()->count());
    }
}
