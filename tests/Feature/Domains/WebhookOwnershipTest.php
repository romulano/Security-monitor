<?php

namespace Tests\Feature\Domains;

use App\Models\LookalikeWatch;
use App\Models\OwnedDomain;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Webhook\WebhookOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeProfileIndex;
use Tests\Feature\Alerts\WebhookTestHelpers;
use Tests\TestCase;

/**
 * O webhook na posse da conta (DomainOwnership): comprovado nele vale no Radar e em Notícias, e o
 * comprovado nos outros vale nele — sem prova por registro TXT no DNS (instalação de um dono só).
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

    public function test_the_webhook_domain_is_born_verified_and_proves_it_in_the_radar(): void
    {
        $user = $this->client();
        $this->actingAs($user)->post(route('alerts.webhooks.store'), ['nome' => 'Produção', 'url' => self::HOOK, 'autenticacao' => 'gerar'])
            ->assertSessionHas('status', 'Webhook cadastrado. Mande um teste para conferir o seu servidor.');
        $webhook = $user->webhooks()->sole();

        $this->assertTrue($webhook->domain->isVerified());
        $this->assertSame('active', $webhook->status);
        $this->assertSame('webhook', OwnedDomain::query()->where('domain', 'cliente.com.br')->value('source'));
        $this->assertTrue(app(WebhookOwnership::class)->holds($webhook));

        // O Radar do mesmo domínio chega comprovado, sem consulta ao DNS.
        $this->dns->asked = [];
        $this->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => 'cliente.com.br']);
        $this->assertTrue(LookalikeWatch::query()->sole()->domain->isVerified());
        $this->assertSame([], array_filter($this->dns->asked, fn ($q) => str_starts_with($q, 'txt')));
        $this->get(route('alerts.lookalikes.interview', ['passo' => 1]))->assertOk();
    }

    public function test_a_subdomain_of_a_domain_proven_in_news_needs_nothing_in_the_webhook(): void
    {
        $user = $this->client();
        $this->proveDomain($user->id, 'cliente.com.br', 'tokdenoticias', 'news');

        $this->actingAs($user)->post(route('alerts.webhooks.store'), ['nome' => 'Produção', 'url' => self::HOOK, 'autenticacao' => 'gerar'])
            ->assertSessionHas('status', 'Webhook cadastrado. Mande um teste para conferir o seu servidor.');
        $webhook = $user->webhooks()->sole();

        $this->assertSame(['active', 'cliente.com.br'], [$webhook->status, $webhook->domain->domain]);
        $this->assertTrue(app(WebhookOwnership::class)->holds($webhook), 'hooks.cliente.com.br está coberto por cliente.com.br');
    }
}
