<?php

namespace Tests\Feature\Domains;

use App\Models\LookalikeWatch;
use App\Models\OwnedDomain;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Webhook\WebhookOwnership;
use App\Services\Domains\DomainOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeProfileIndex;
use Tests\Feature\Alerts\WebhookTestHelpers;
use Tests\TestCase;

/**
 * O webhook na posse da conta (DomainOwnership): comprovado nele vale no Radar e em Notícias, o
 * comprovado nos outros vale nele, e a posse que cai para o envio, como nos outros serviços.
 */
class WebhookOwnershipTest extends TestCase
{
    use RefreshDatabase, WebhookTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        $this->fakeDns();
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('', 204)]);
    }

    public function test_proven_in_the_webhook_is_proven_in_the_radar_and_the_fall_stops_the_sending(): void
    {
        $user = $this->client();
        $this->actingAs($user)->post(route('alerts.webhooks.store'), ['nome' => 'Produção', 'url' => self::HOOK, 'autenticacao' => 'gerar']);
        $webhook = $user->webhooks()->sole();
        $this->assertSame(app(DomainOwnership::class)->token($user->refresh()), $webhook->domain->verify_token, 'o token da conta');

        $txt = $webhook->domain->txtValue();
        // O quadro da posse é o mesmo de Notícias, do Radar e do monitor.
        $this->get(route('alerts.webhooks.show', $webhook))
            ->assertOk()
            ->assertSee('Crie este registro TXT no DNS de')
            ->assertSee('value="'.$txt.'"', false)
            ->assertSee('É o mesmo registro para todos os serviços do Security Monitor: com ele no DNS, nenhum outro pede de novo.');

        $this->dns->set('cliente.com.br', 'txt', [$txt]);
        $this->post(route('alerts.webhooks.verify', $webhook))->assertSessionHas('status');
        $this->assertSame('active', $webhook->refresh()->status);
        $this->assertSame('webhook', OwnedDomain::query()->where('domain', 'cliente.com.br')->value('source'));

        // O Radar do mesmo domínio chega comprovado, sem consulta ao DNS.
        $this->dns->asked = [];
        $this->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => 'cliente.com.br']);
        $this->assertTrue(LookalikeWatch::query()->sole()->domain->isVerified());
        $this->assertSame([], array_filter($this->dns->asked, fn ($q) => str_starts_with($q, 'txt')));
        $this->get(route('alerts.lookalikes.interview', ['passo' => 1]))->assertOk();

        // Sem o TXT por 7 dias, a posse cai: a cópia do webhook também, e o Security Monitor para de chamar o endereço.
        $this->dns->set('cliente.com.br', 'txt', []);
        foreach (range(1, 8) as $day) {
            $this->travel(25)->hours();
            $this->artisan('secmon:alerts-verify-domains')->assertSuccessful();
        }

        $this->assertNull($webhook->refresh()->domain->verified_at);
        $this->assertFalse(app(WebhookOwnership::class)->holds($webhook));

        // O TXT de volta: o agendador acha sozinho e a cópia volta a valer.
        $this->dns->set('cliente.com.br', 'txt', [$txt]);
        $this->travel(10)->minutes();
        $this->artisan('secmon:alerts-verify-domains')->assertSuccessful();
        $this->assertTrue(app(WebhookOwnership::class)->holds($webhook->refresh()));
    }

    public function test_a_subdomain_of_a_domain_proven_in_news_needs_nothing_in_the_webhook(): void
    {
        $user = $this->client();
        $this->proveDomain($user->id, 'cliente.com.br', 'tokdenoticias', 'news');

        $this->actingAs($user)->post(route('alerts.webhooks.store'), ['nome' => 'Produção', 'url' => self::HOOK, 'autenticacao' => 'gerar'])
            ->assertSessionHas('status', 'Webhook cadastrado com o domínio que você já comprovou. Mande um teste para conferir o seu servidor.');
        $webhook = $user->webhooks()->sole();

        $this->assertSame(['active', 'cliente.com.br', 'tokdenoticias'], [$webhook->status, $webhook->domain->domain, $webhook->domain->verify_token]);
        $this->assertTrue(app(WebhookOwnership::class)->holds($webhook), 'hooks.cliente.com.br está coberto por cliente.com.br');
    }
}
