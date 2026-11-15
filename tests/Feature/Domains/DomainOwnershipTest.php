<?php

namespace Tests\Feature\Domains;

use App\Exceptions\ActionRefused;
use App\Jobs\DiscoverDomainStack;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\SecuritySite;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\DomainOwnership;
use App\Services\Pentest\PentestEnrollment;
use App\Services\Security\SiteOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\TestCase;

/**
 * A posse de domínio é uma só para a conta (DomainOwnership): comprovada num serviço, nenhum outro
 * pede de novo; as mesmas regras para todos (o TXT do próprio nome, o CNAME perguntado antes, o
 * domínio de cima cobrindo os subdomínios) e o mesmo quadro em todas as telas.
 */
class DomainOwnershipTest extends TestCase
{
    use LookalikeTestHelpers, RefreshDatabase;

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

    /** O domínio do alerta de notícias, pelo passo 5 da entrevista, com o aceite. */
    private function news(User $user, string $name): MonitoredDomain
    {
        $this->actingAs($user)->put(route('alerts.interview.update', 5), [
            'answers' => ['has_domain' => 'sim', 'domain' => $name], 'consent' => '1', 'acao' => 'ficar',
        ])->assertRedirect();

        return MonitoredDomain::query()->ofAlerts()->where('user_id', $user->id)->where('domain', $name)->sole();
    }

    /** O domínio de um Radar, pelos passos 1 (digitado) e 2 da entrevista dele: o passo 3 é o da posse. */
    private function radar(User $user, string $name): LookalikeWatch
    {
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => $name])->assertRedirect();
        $watch = LookalikeWatch::query()->where('user_id', $user->id)->whereHas('domain', fn ($q) => $q->where('domain', $name))->sole();
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 2, 'radar' => $watch->id]), ['empresa' => 'Acme', 'marcas' => ['Acme']])->assertRedirect();

        return $watch->refresh();
    }

    private function txt(User $user): string
    {
        return 'secmon-verify='.$this->ownership()->token($user->refresh());
    }

    public function test_the_account_has_one_token_and_every_service_shows_it(): void
    {
        $user = $this->client();
        $token = $this->ownership()->token($user);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $token);
        $this->assertSame($token, $this->ownership()->token($user->fresh()), 'nasce uma vez só');
        $this->assertNotSame($token, $this->ownership()->token($this->client()), 'cada conta tem o seu');

        $this->assertSame($token, $this->news($user, 'acme.com.br')->verify_token);
        $this->assertSame($token, $this->radar($user, 'outra.com.br')->domain->verify_token);
        $this->assertSame($token, app(PentestEnrollment::class)->add($user, null, 'nova.com.br', null)->domain->verify_token);
    }

    public function test_proven_in_news_is_proven_in_the_radar_and_in_the_tests_without_asking_again(): void
    {
        $user = $this->client();
        $news = $this->news($user, 'acme.com.br');
        $this->assertFalse($news->isVerified());

        $this->dns->set('acme.com.br', 'txt', [$this->txt($user)]);
        $this->actingAs($user)->post(route('alerts.domain.verify'))->assertSessionHas('status');
        $this->assertTrue($news->refresh()->isVerified());
        Queue::assertPushed(DiscoverDomainStack::class, fn ($job) => $job->domainId === $news->id);

        // Nem uma consulta ao DNS: a posse já é da conta.
        $this->dns->asked = [];
        $watch = $this->radar($user, 'acme.com.br');
        $this->assertTrue($watch->domain->isVerified());
        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 3, 'radar' => $watch->id]))
            ->assertOk()
            ->assertSee('posse comprovada')
            ->assertSee('Encontramos o TXT em <span class="inline-block max-w-full font-mono [overflow-wrap:anywhere]">acme.com.br</span>', false)
            ->assertDontSee('Crie este registro TXT');

        $copy = app(PentestEnrollment::class)->add($user, null, 'acme.com.br', null)->domain;
        $this->assertTrue($copy->isVerified(), 'o nome digitado nos testes também chega comprovado');
        $this->assertSame($news->verify_token, $copy->verify_token, 'com o token da prova, o que está no DNS');
        $this->assertSame(['proof' => 'news', 'verified' => true], json_decode(DB::table('pentest_audit')->value('data'), true));
        $this->assertSame([], $this->dns->asked);
    }

    public function test_proven_in_the_tests_or_in_the_monitor_is_proven_in_news_and_in_the_radar(): void
    {
        // Nos testes de segurança, pela regra deles (o token da cópia no próprio nome).
        $user = $this->client();
        $pentest = app(PentestEnrollment::class)->add($user, null, 'nova.com.br', null);
        $this->dns->set('nova.com.br', 'txt', [$pentest->domain->txtValue()]);
        $this->assertTrue(app(PentestEnrollment::class)->verify($pentest, null));

        $this->assertTrue($this->radar($user, 'nova.com.br')->domain->isVerified());
        $this->assertTrue($this->news($user, 'nova.com.br')->isVerified());
        $this->assertSame('tests', OwnedDomain::query()->where('domain', 'nova.com.br')->value('source'));

        // No monitor de sites do admin, achado na varredura.
        $admin = $this->adminUser();
        $this->actingAs($admin)->post(route('admin.security.store'), ['host' => 'meusite.com.br', 'consent' => '1']);
        $site = SecuritySite::query()->sole();
        $this->dns->set('meusite.com.br', 'txt', [$site->txtValue()]);
        $this->assertSame('txt', app(SiteOwnership::class)->refresh($site));

        $this->actingAs($admin)->get(route('alerts.lookalikes.interview', ['passo' => 1]))
            ->assertOk()->assertSee('Já comprovados no Security Monitor')->assertSee('no monitor de sites. Usando este, o passo 3 já vem pronto.');
        $this->assertTrue($this->radar($admin, 'meusite.com.br')->domain->isVerified());
    }

    public function test_the_proven_domain_covers_its_subdomains_but_not_in_the_tests(): void
    {
        $user = $this->client();
        $this->dns->set('empresa.com.br', 'txt', [$this->txt($user)]);
        $this->assertTrue($this->ownership()->verifyRow($this->news($user, 'empresa.com.br'), 'news'));

        $this->dns->asked = [];
        $watch = $this->radar($user, 'loja.empresa.com.br');
        $this->assertTrue($watch->domain->isVerified(), 'o domínio de cima cobre o subdomínio');
        $this->assertSame([], $this->dns->asked);
        $this->actingAs($user)->get(route('alerts.lookalikes.interview', ['passo' => 3, 'radar' => $watch->id]))
            ->assertOk()->assertSee('para os outros subdomínios.');

        // Os testes de segurança pedem o TXT no próprio nome (regra do Red Team): o de cima não vale lá.
        $copy = app(PentestEnrollment::class)->add($user, null, 'loja.empresa.com.br', null)->domain;
        $this->assertFalse($copy->isVerified());
        $this->assertFalse($this->ownership()->verifyCopy($copy));
        $this->assertFalse($this->ownership()->recheckCopy($copy));
    }

    public function test_a_subdomain_is_proven_by_the_txt_on_the_main_domain_and_never_by_the_public_suffix(): void
    {
        $user = $this->client();
        $row = $this->news($user, 'app.loja.com.br');
        $this->dns->set('loja.com.br', 'txt', ['v=spf1 -all', $this->txt($user)]);
        $this->dns->asked = [];

        $this->assertTrue($this->ownership()->verifyRow($row, 'news'));
        $this->assertSame(['txt app.loja.com.br', 'txt loja.com.br'], $this->dns->asked, 'até o domínio principal, nunca o com.br');
        $this->assertTrue(OwnedDomain::query()->where('domain', 'loja.com.br')->sole()->isVerified(), 'a prova é do nome onde está o TXT');
        $this->assertNull(OwnedDomain::query()->where('domain', 'app.loja.com.br')->sole()->verified_at);
        $this->assertTrue($row->refresh()->isVerified());

        // A tela de um subdomínio sem prova sugere o domínio principal, que vale para todos.
        $other = $this->client();
        $this->news($other, 'blog.outra.com.br');
        $this->actingAs($other)->get(route('alerts.interview', 5))
            ->assertOk()
            ->assertSee('Crie este registro TXT no DNS de')
            ->assertSee('outra.com.br')
            ->assertSee('No domínio principal, o registro vale para')
            ->assertSee('crie o registro com o nome <span class="font-mono">blog</span>', false);
    }

    public function test_the_txt_that_arrives_through_a_cname_never_proves_the_alias(): void
    {
        $user = $this->client();
        $row = $this->news($user, 'loja.empresa.com.br');
        $this->dns->set('loja.empresa.com.br', 'cname', ['x.agencia.com'])->set('x.agencia.com', 'txt', [$this->txt($user)]);

        $this->assertFalse($this->ownership()->verifyRow($row, 'news'));

        try {
            $this->ownership()->confirmRow($row, 'news');
            $this->fail('O apelido não devia provar a posse.');
        } catch (ActionRefused $e) {
            $this->assertSame('loja.empresa.com.br é um apelido (CNAME) de x.agencia.com. Um apelido não tem TXT próprio: o TXT que aparece nele é o do destino, e não prova que o nome é seu. Crie o TXT em empresa.com.br: ele vale também para os subdomínios.', $e->getMessage());
        }

        // O TXT no domínio principal (a zona do cliente) continua valendo para o apelido.
        $this->dns->set('empresa.com.br', 'txt', [$this->txt($user)]);
        $this->ownership()->confirmRow($row, 'news');
        $this->assertTrue($row->refresh()->isVerified());
        $this->assertNull(OwnedDomain::query()->where('domain', 'loja.empresa.com.br')->value('verified_at'));
    }

    public function test_any_token_of_the_account_proves_and_no_token_of_another_account_does(): void
    {
        $ana = $this->client();
        $bia = $this->client();
        // O token de antes, que Ana já publicou noutro domínio, continua valendo nos outros.
        $this->proveDomain($ana->id, 'antigo.com.br', 'legado9999legado9');

        $row = $this->news($ana, 'novo.com.br');
        $this->dns->set('novo.com.br', 'txt', ['secmon-verify='.$this->ownership()->token($bia)]);
        $this->assertFalse($this->ownership()->verifyRow($row, 'news'), 'o token de outra conta não prova nada');

        $this->dns->set('novo.com.br', 'txt', ['secmon-verify=legado9999legado9']);
        $this->assertTrue($this->ownership()->verifyRow($row, 'news'));
        $this->assertSame('legado9999legado9', OwnedDomain::query()->where('domain', 'novo.com.br')->value('proof_token'));

        $other = $this->news($bia, 'novo.com.br');
        $this->assertFalse($this->ownership()->verifyRow($other, 'news'), 'nem a prova de outra conta, no mesmo nome');
    }

    public function test_the_ownership_box_is_the_same_in_news_in_the_radar_and_in_the_monitor(): void
    {
        $admin = $this->adminUser();
        $txt = $this->txt($admin);
        $this->news($admin, 'acme.com.br');
        $watch = $this->radar($admin, 'outra.com.br');
        $this->actingAs($admin)->post(route('admin.security.store'), ['host' => 'meusite.com.br', 'consent' => '1']);

        // No monitor, o botão do quadro é "Conferir agora": o "Verificar agora" da página é a varredura do site.
        foreach ([
            [route('alerts.interview', 5), 'Verificar agora'],
            [route('alerts.lookalikes.interview', ['passo' => 3, 'radar' => $watch->id]), 'Verificar agora'],
            [route('admin.security.show', SecuritySite::query()->sole()), 'Conferir agora'],
        ] as [$page, $button]) {
            $this->actingAs($admin)->get($page)
                ->assertOk()
                ->assertSee('Crie este registro TXT no DNS de')
                ->assertSee('aguardando o TXT')
                ->assertSee('value="'.$txt.'"', false)
                ->assertSee('hover:brightness-110">'.$button.'</button>', false)
                ->assertSee('É o mesmo registro para todos os serviços do Security Monitor: com ele no DNS, nenhum outro pede de novo.');
        }
    }
}
