<?php

namespace Tests\Feature\Domains;

use App\Jobs\DiscoverDomainStack;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\SecuritySite;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Domains\DomainOwnership;
use App\Services\Pentest\PentestEnrollment;
use App\Services\Pentest\PentestOwnership;
use App\Services\Security\SiteOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\Feature\Pentest\PentestTestHelpers;
use Tests\TestCase;

/**
 * A reconferência da posse, a mesma para todos (o secmon:alerts-verify-domains): uma vez por dia, e
 * o TXT sumido por 7 dias derruba a posse em Notícias, no Radar e no monitor, que voltam sozinhos
 * quando ele reaparece. A prova de antes da unificação não cai sozinha. A stack de testes segue com a
 * regra dela: confere ao vivo, sem prazo, e a reconferência diária não mexe nela.
 */
class OwnershipLossTest extends TestCase
{
    use LookalikeTestHelpers, PentestTestHelpers, RefreshDatabase;

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

    private function owned(string $name): OwnedDomain
    {
        return OwnedDomain::query()->where('domain', $name)->sole();
    }

    /** O domínio do alerta de notícias, pelo passo 5 da entrevista, com o aceite. */
    private function news(User $user, string $name): MonitoredDomain
    {
        $this->actingAs($user)->put(route('alerts.interview.update', 5), [
            'answers' => ['has_domain' => 'sim', 'domain' => $name], 'consent' => '1', 'acao' => 'ficar',
        ])->assertRedirect();

        return MonitoredDomain::query()->ofAlerts()->where('user_id', $user->id)->where('domain', $name)->sole();
    }

    /** O Radar do domínio, pela entrevista dele, ativado com o aceite dos prints. */
    private function activeRadar(User $user, string $name): LookalikeWatch
    {
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => $name]);
        $watch = LookalikeWatch::query()->where('user_id', $user->id)->sole();
        $this->actingAs($user)->put(route('alerts.lookalikes.interview.update', ['passo' => 2, 'radar' => $watch->id]), ['empresa' => 'Acme', 'marcas' => ['Acme']]);
        $this->actingAs($user)->post(route('alerts.lookalikes.interview.activate', ['radar' => $watch->id]), ['consent' => '1']);

        return $watch->refresh();
    }

    private function sweep(): void
    {
        $this->artisan('secmon:alerts-verify-domains')->assertSuccessful();
    }

    public function test_the_txt_gone_for_seven_days_drops_the_proof_everywhere_and_it_comes_back_with_the_txt(): void
    {
        $admin = $this->adminUser();
        $txt = 'secmon-verify='.$this->ownership()->token($admin);
        $this->dns->set('acme.com.br', 'txt', [$txt]);
        $news = $this->news($admin, 'acme.com.br');
        $this->assertTrue($this->ownership()->verifyRow($news, 'news'));
        $watch = $this->activeRadar($admin, 'acme.com.br');
        $this->assertSame('active', $watch->status);
        $this->actingAs($admin)->post(route('admin.security.store'), ['host' => 'acme.com.br', 'consent' => '1']);
        $site = SecuritySite::query()->sole();
        $this->assertSame('txt', $site->ownership);

        // O TXT sai do DNS: a reconferência do dia seguinte conta o prazo, e nada cai ainda.
        $this->dns->set('acme.com.br', 'txt', []);
        $this->travel(25)->hours();
        $this->artisan('secmon:alerts-verify-domains')->expectsOutputToContain('1 posse(s) reconferida(s), 0 caída(s)')->assertSuccessful();
        $owned = $this->owned('acme.com.br');
        $this->assertSame('missing', $owned->check_status);
        $this->assertTrue($owned->isVerified());
        $this->assertTrue($news->refresh()->isVerified());
        $this->assertSame('active', $watch->refresh()->status);
        // A tela avisa com o prazo; o monitor, que confere ao vivo em cada varredura, já para os arquivos expostos.
        $this->actingAs($admin)->get(route('alerts.interview', 5))
            ->assertOk()->assertSee('Publique de novo este registro TXT no DNS de')->assertSee('sem ele, a posse cai e a descoberta para.');
        $this->assertNull(app(SiteOwnership::class)->refresh($site));

        // Sete dias depois, sem o TXT, a posse cai em todos os serviços.
        foreach (range(1, 7) as $day) {
            $this->travel(25)->hours();
            $this->sweep();
        }

        $owned->refresh();
        $this->assertNull($owned->verified_at);
        $this->assertNotNull($owned->lost_at);
        $this->assertNull($news->refresh()->verified_at);
        $this->assertFalse($news->canDiscover(), 'a descoberta do alerta para');
        $this->assertSame('waiting', $watch->refresh()->status, 'o Radar volta a esperar o TXT, com o histórico guardado');
        $this->actingAs($admin)->get(route('alerts.interview', 5))->assertOk()->assertSee('Crie este registro TXT no DNS de');

        // O TXT de volta: o agendador acha sozinho, e cada serviço retoma.
        $this->dns->set('acme.com.br', 'txt', [$txt]);
        $this->travel(10)->minutes();
        $this->artisan('secmon:alerts-verify-domains')->expectsOutputToContain('1 verificado(s)')->assertSuccessful();
        $this->assertTrue($owned->refresh()->isVerified());
        $this->assertTrue($news->refresh()->isVerified());
        $this->assertSame('active', $watch->refresh()->status);
        Queue::assertPushed(DiscoverDomainStack::class, 2);
    }

    public function test_a_dns_that_stays_silent_counts_like_a_missing_txt(): void
    {
        $user = $this->client();
        $this->dns->set('acme.com.br', 'txt', ['secmon-verify='.$this->ownership()->token($user)]);
        $news = $this->news($user, 'acme.com.br');
        $this->ownership()->verifyRow($news, 'news');

        // Um dia sem resposta e depois o TXT: nada muda.
        $this->dns->failing['acme.com.br'] = 1;
        $this->travel(25)->hours();
        $this->sweep();
        $this->assertSame('unknown', $this->owned('acme.com.br')->check_status);
        $this->travel(25)->hours();
        $this->sweep();
        $this->assertNull($this->owned('acme.com.br')->unseen_since);

        // Mudo por 7 dias: cai.
        $this->dns->failing['acme.com.br'] = 100;
        foreach (range(1, 8) as $day) {
            $this->travel(25)->hours();
            $this->sweep();
        }

        $this->assertNull($this->owned('acme.com.br')->verified_at);
        $this->assertNull($news->refresh()->verified_at);
    }

    public function test_a_proof_from_before_the_unification_does_not_fall_until_the_txt_is_seen_again(): void
    {
        // O Radar dizia "Pode apagar o registro, se quiser: a comprovação fica guardada".
        $watch = $this->watch($this->verifiedDomain($user = $this->client(), 'acmepay.com.br'));
        $this->owned('acmepay.com.br')->forceFill(['legacy' => true, 'checked_at' => null])->save();

        foreach (range(1, 30) as $day) {
            $this->travel(25)->hours();
            $this->sweep();
        }

        $owned = $this->owned('acmepay.com.br');
        $this->assertTrue($owned->isVerified());
        $this->assertNotNull($owned->unseen_since);
        $this->assertNull($owned->fallsAt());
        $this->assertSame('active', $watch->refresh()->status);
        $this->actingAs($user)->get(route('alerts.lookalikes', ['radar' => $watch->id]))->assertOk()->assertDontSee('TXT sumiu');

        // Visto de novo, segue a regra de todas: some por 7 dias, cai.
        $this->dns->set('acmepay.com.br', 'txt', ['secmon-verify=abc123']);
        $this->travel(25)->hours();
        $this->sweep();
        $this->assertFalse($this->owned('acmepay.com.br')->legacy);

        $this->dns->set('acmepay.com.br', 'txt', []);
        foreach (range(1, 8) as $day) {
            $this->travel(25)->hours();
            $this->sweep();
        }

        $this->assertNull($this->owned('acmepay.com.br')->verified_at);
        $this->assertSame('waiting', $watch->refresh()->status);
    }

    public function test_the_test_stack_keeps_rechecking_live_with_its_own_rule(): void
    {
        $user = $this->client();
        $txt = 'secmon-verify='.$this->ownership()->token($user);
        $this->dns->set('empresa.com.br', 'txt', [$txt]);
        $this->ownership()->verifyRow($this->news($user, 'empresa.com.br'), 'news');
        $pentest = app(PentestEnrollment::class)->add($user, null, 'empresa.com.br', null);
        $this->assertTrue($pentest->ownershipHolds(), 'leu a prova da conta: não pediu o TXT de novo');

        // A reconferência dela vai ao DNS na hora (o portão a chama em cada fase), com a auditoria.
        $this->dns->asked = [];
        $this->assertTrue(app(PentestOwnership::class)->recheck($pentest));
        $this->assertSame(['txt empresa.com.br'], $this->dns->asked);

        // Sem o TXT, para na hora: o prazo de 7 dias da conta não vale para os testes.
        $this->dns->set('empresa.com.br', 'txt', []);
        $this->assertFalse(app(PentestOwnership::class)->recheck($pentest));
        $this->assertFalse($pentest->refresh()->ownershipHolds());
        $this->assertTrue($this->owned('empresa.com.br')->isVerified());
        $checks = DB::table('pentest_audit')->where('event', 'ownership.checked')->count();

        // A reconferência diária da conta não mexe na stack: nem no estado dela, nem na auditoria.
        $this->dns->set('empresa.com.br', 'txt', [$txt]);
        $this->travel(25)->hours();
        $this->sweep();
        $this->assertSame('missing', $pentest->refresh()->ownership_status);
        $this->assertSame($checks, DB::table('pentest_audit')->where('event', 'ownership.checked')->count());
    }

    public function test_a_proof_whose_txt_left_the_dns_does_not_reach_the_tests(): void
    {
        $user = $this->client();
        $this->dns->set('acme.com.br', 'txt', ['secmon-verify='.$this->ownership()->token($user)]);
        $news = $this->news($user, 'acme.com.br');
        $this->ownership()->verifyRow($news, 'news');
        $this->assertSame(['acme.com.br'], app(PentestEnrollment::class)->provenDomains($user)->pluck('domain')->all());

        $this->dns->set('acme.com.br', 'txt', []);
        $this->travel(25)->hours();
        $this->sweep();

        // Notícias segue comprovado dentro do prazo; os testes, que parariam na 1ª conferência, pedem o TXT.
        $this->assertTrue($news->refresh()->isVerified());
        $this->assertSame([], app(PentestEnrollment::class)->provenDomains($user)->all());
        $this->assertFalse(app(PentestEnrollment::class)->add($user, null, 'acme.com.br', null)->ownershipHolds());
    }

    public function test_a_pending_copy_uses_the_proof_the_account_got_later(): void
    {
        $this->openArea();
        $user = $this->grant($this->client());
        $pentest = app(PentestEnrollment::class)->add($user, null, 'empresa.com.br', null);
        $this->assertFalse($pentest->ownershipHolds());

        // Depois, o mesmo nome é comprovado em Notícias com o token que o cliente já tinha publicado.
        $this->proveDomain($user->id, 'empresa.com.br', 'legado9999legado9', 'news');
        $this->dns->set('empresa.com.br', 'txt', ['secmon-verify=legado9999legado9']);

        $this->actingAs($user)->get(route('alerts.tests.authorize', ['passo' => 2, 'dominio' => 'empresa.com.br']))
            ->assertOk()->assertSee('Este domínio já está comprovado em Notícias: clique em “Verificar agora” para usar a mesma prova.');

        $this->actingAs($user)->post(route('alerts.tests.domains.verify', ['dominio' => 'empresa.com.br']))
            ->assertSessionHas('status', 'Posse comprovada: o registro TXT está no DNS de empresa.com.br.');

        $copy = $pentest->domain->refresh();
        $this->assertSame('legado9999legado9', $copy->verify_token, 'confere o token que está no DNS, sempre no próprio nome');
        $this->assertTrue($pentest->refresh()->ownershipHolds());
        $this->assertSame(['domain.added', 'ownership.verified'], DB::table('pentest_audit')->orderBy('id')->pluck('event')->all());
    }
}
