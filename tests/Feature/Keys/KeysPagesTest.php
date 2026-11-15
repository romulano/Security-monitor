<?php

namespace Tests\Feature\Keys;

use App\Jobs\ScanSiteKeys;
use App\Models\ExposedKey;
use App\Models\KeySite;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\Feature\Pentest\PentestRunHelpers;
use Tests\Feature\Pentest\PentestTestHelpers;
use Tests\TestCase;

/**
 * A área "Chaves expostas" (Integração → Chaves expostas): só os domínios comprovados da conta, o
 * "Ativar e varrer", o "Varrer agora", pausar e retomar, a lista das chaves de cada domínio (as a
 * decidir primeiro, as que não deveriam estar no site antes das públicas) e a decisão do dono. O
 * domínio de outra conta, o não comprovado e a chave de outro domínio são 404.
 */
class KeysPagesTest extends TestCase
{
    use LookalikeTestHelpers, PentestRunHelpers, PentestTestHelpers, RefreshDatabase;

    private User $user;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        $this->user = $this->client();
    }

    public function test_only_proven_domains_are_listed_and_activating_queues_the_first_scan(): void
    {
        $owned = $this->proven('loja.com.br');
        OwnedDomain::query()->forceCreate(['user_id' => $this->user->id, 'domain' => 'semprova.com.br', 'source' => 'radar']);

        $this->actingAs($this->user)->get(route('alerts.integrations'))->assertOk()
            ->assertSee('data-source="keys"', false)
            ->assertSee('Chaves expostas')
            ->assertSee('não ativada');

        $this->get(route('alerts.keys'))->assertOk()
            ->assertSee('loja.com.br')
            ->assertDontSee('semprova.com.br')
            ->assertSee('Posse comprovada no Radar de domínios.')
            ->assertSee('Ativar e varrer');
        $this->get(route('alerts.keys.domain', ['dominio' => 'semprova.com.br']))->assertNotFound();
        $this->post(route('alerts.keys.scan', ['dominio' => 'semprova.com.br']))->assertNotFound();

        $this->from(route('alerts.keys'))->post(route('alerts.keys.scan', ['dominio' => 'loja.com.br']))
            ->assertRedirect(route('alerts.keys'))
            ->assertSessionHas('status', 'Varredura de loja.com.br na fila: leva alguns minutos. Depois, ela se repete toda semana.');
        $site = KeySite::query()->where('owned_domain_id', $owned->id)->firstOrFail();
        $this->assertSame(['active', 'queued', $this->user->id], [$site->status, $site->scan_status, $site->user_id]);
        Queue::assertPushedOn('keys', ScanSiteKeys::class, fn (ScanSiteKeys $job) => $job->siteId === $site->id);

        // Na fila, o botão espera; pausar e retomar.
        $this->get(route('alerts.keys'))->assertSee('na fila')->assertSee('Varrendo…');
        $this->from(route('alerts.keys'))->post(route('alerts.keys.scan', ['dominio' => 'loja.com.br']))
            ->assertSessionHas('error', 'Já tem uma varredura deste domínio na fila ou rodando.');
        $site->forceFill(['scan_status' => 'idle'])->save();
        $this->post(route('alerts.keys.pause', ['dominio' => 'loja.com.br']))->assertSessionHas('status', 'Varredura de loja.com.br pausada. As chaves e as suas decisões ficam.');
        $this->assertSame('paused', $site->refresh()->status);
        $this->get(route('alerts.keys'))->assertSee('pausada')->assertSee('Retomar a varredura');
        $this->post(route('alerts.keys.resume', ['dominio' => 'loja.com.br']));
        $this->assertSame('active', $site->refresh()->status);
    }

    public function test_the_owner_sees_the_keys_with_the_suggestion_and_decides(): void
    {
        $owned = $this->proven('loja.com.br');
        $stripe = $this->key($owned, 'stripe_publishable', 'pk_live_••••••••Q2wE (40 caracteres)', 'STRIPE_KEY', 'a1');
        $aws = $this->key($owned, 'aws_access_key', 'AKIA••••••••7XQ2 (20 caracteres)', null, 'b2');

        $this->actingAs($this->user)->get(route('alerts.keys'))->assertSee('2 chaves')->assertSee('2 para decidir');

        // A que não deveria estar no site vem antes da pública; a chave só mascarada, com o nome e os lugares.
        $this->get(route('alerts.keys.domain', ['dominio' => 'loja.com.br']))
            ->assertOk()
            ->assertSeeInOrder(['Para decidir', 'Chave de acesso da AWS', 'não deveria estar no site', 'AKIA••••••••7XQ2 (20 caracteres)',
                'Chave publicável do Stripe', 'feita para ser pública', 'pk_live_••••••••Q2wE (40 caracteres)', 'no nome', 'STRIPE_KEY'])
            ->assertSee('loja.com.br/assets/app.js · 2×')
            ->assertDontSee('Decididas');

        $this->put(route('alerts.keys.classify', ['dominio' => 'loja.com.br', 'chave' => $aws->id]), ['verdict' => 'leak'])
            ->assertRedirect(route('alerts.keys.domain', ['dominio' => 'loja.com.br']).'#chave-'.$aws->id)
            ->assertSessionHas('status', 'Chave de acesso da AWS marcada como uma chave que não deveria estar exposta. Troque-a no serviço e tire-a do site.');
        $this->put(route('alerts.keys.classify', ['dominio' => 'loja.com.br', 'chave' => $stripe->id]), ['verdict' => 'allowed']);

        $this->assertSame(['leak', $this->user->id], [$aws->refresh()->verdict, $aws->verdict_by]);
        $this->assertSame('allowed', $stripe->refresh()->verdict);
        $this->get(route('alerts.keys.domain', ['dominio' => 'loja.com.br']))
            ->assertSee('0 para decidir, 1 que não deveria estar exposta e 1 que pode ficar.')
            ->assertSeeInOrder(['Decididas', 'não deveria estar exposta', 'Troque a chave no serviço', 'Desfazer a decisão', 'pode ficar exposta'])
            ->assertDontSee('Para decidir');

        $this->put(route('alerts.keys.classify', ['dominio' => 'loja.com.br', 'chave' => $aws->id]), ['verdict' => ''])
            ->assertSessionHas('status', 'Decisão desfeita: Chave de acesso da AWS voltou para as chaves a decidir.');
        $this->assertSame([null, null, null], [$aws->refresh()->verdict, $aws->verdict_by, $aws->verdict_at]);
    }

    public function test_only_the_owner_reaches_the_keys_and_only_the_two_verdicts_are_taken(): void
    {
        $owned = $this->proven('loja.com.br');
        $key = $this->key($owned, 'jwt', 'eyJ••••••••x9Zq (180 caracteres)', 'token', 'c3');
        $other = $this->client();
        $mine = OwnedDomain::query()->forceCreate(['user_id' => $other->id, 'domain' => 'outra.com.br', 'source' => 'radar', 'verified_at' => now()]);

        $this->actingAs($other)->get(route('alerts.keys'))->assertSee('outra.com.br')->assertDontSee('loja.com.br');
        $this->get(route('alerts.keys.domain', ['dominio' => 'loja.com.br']))->assertNotFound();
        $this->post(route('alerts.keys.scan', ['dominio' => 'loja.com.br']))->assertNotFound();
        $this->put(route('alerts.keys.classify', ['dominio' => 'loja.com.br', 'chave' => $key->id]), ['verdict' => 'allowed'])->assertNotFound();
        // A chave de um domínio pelo endereço de outro domínio também é 404.
        $this->put(route('alerts.keys.classify', ['dominio' => $mine->domain, 'chave' => $key->id]), ['verdict' => 'allowed'])->assertNotFound();

        $this->actingAs($this->user)->put(route('alerts.keys.classify', ['dominio' => 'loja.com.br', 'chave' => $key->id]), ['verdict' => 'apagar'])
            ->assertSessionHasErrors('verdict');
        $this->put(route('alerts.keys.classify', ['dominio' => 'loja.com.br', 'chave' => $key->id]), [])->assertSessionHasErrors('verdict');
        $this->assertNull($key->refresh()->verdict);
        Queue::assertNothingPushed();
    }

    public function test_the_owner_lists_the_extra_addresses_the_scan_always_visits(): void
    {
        $owned = $this->proven('loja.com.br');

        $this->actingAs($this->user)->get(route('alerts.keys.domain', ['dominio' => 'loja.com.br']))->assertOk()->assertSee('Endereços extras');
        $this->from(route('alerts.keys.domain', ['dominio' => 'loja.com.br']))
            ->put(route('alerts.keys.paths', ['dominio' => 'loja.com.br']), ['paths' => "/app/facial-auth/\r\n\r\n https://www.loja.com.br/redefine/facial-auth \n/app/facial-auth/"])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '2 endereços extras salvos: a próxima varredura visita todos primeiro.');

        // O domínio ainda não ativado ganha a linha, sem varredura pedida.
        $site = KeySite::query()->where('owned_domain_id', $owned->id)->firstOrFail();
        $this->assertSame([['/app/facial-auth/', 'https://www.loja.com.br/redefine/facial-auth'], 'idle'], [$site->extra_paths, $site->scan_status]);
        Queue::assertNothingPushed();
        $this->get(route('alerts.keys.domain', ['dominio' => 'loja.com.br']))->assertSee('Endereços extras (2)')->assertSee("/app/facial-auth/\nhttps://www.loja.com.br/redefine/facial-auth", false);

        // Fora do domínio, com espaço ou de protocolo esquisito: recusado, e nada muda.
        foreach (['https://terceiro.com/x', 'javascript:alert(1)', '/com espaco', '//cdn.terceiro.com/x'] as $bad) {
            $this->put(route('alerts.keys.paths', ['dominio' => 'loja.com.br']), ['paths' => "/ok\n{$bad}"])->assertSessionHasErrors('paths');
        }

        $this->put(route('alerts.keys.paths', ['dominio' => 'loja.com.br']), ['paths' => implode("\n", array_map(fn (int $i) => "/p{$i}", range(1, 51)))])
            ->assertSessionHasErrors(['paths' => 'No máximo 50 endereços.']);
        $this->assertCount(2, $site->refresh()->extra_paths);

        // Vazio apaga; outra conta não chega.
        $this->put(route('alerts.keys.paths', ['dominio' => 'loja.com.br']), ['paths' => ''])
            ->assertSessionHas('status', 'Sem endereços extras: a varredura segue os links, os sitemaps e o Web Archive.');
        $this->assertNull($site->refresh()->extra_paths);
        $this->actingAs($this->client())->put(route('alerts.keys.paths', ['dominio' => 'loja.com.br']), ['paths' => '/x'])->assertNotFound();
    }

    public function test_without_a_proven_domain_the_page_says_how_to_prove_one(): void
    {
        $this->actingAs($this->user)->get(route('alerts.keys'))->assertOk()
            ->assertSee('Nenhum domínio comprovado na sua conta.')
            ->assertSee('href="'.route('alerts.lookalikes.interview', ['passo' => 1]).'"', false);
        $this->get(route('alerts.integrations'))->assertSee('sem domínio comprovado');
    }

    public function test_the_security_tests_domain_page_points_to_the_keys_area(): void
    {
        $this->switchOn();
        $this->user = $this->grant($this->user);
        $domain = $this->authorized();

        $this->actingAs($this->user)->get(route('alerts.tests.domains.show', ['dominio' => 'empresa.com.br']))
            ->assertOk()
            ->assertSee('Nenhuma chave achada.');
        $this->key($domain->ownedProof(), 'jwt', 'eyJ••••••••x9Zq (180 caracteres)', 'token', 'c3', 'pentest');
        $this->get(route('alerts.tests.domains.show', ['dominio' => 'empresa.com.br']))
            ->assertSee('1 chave achada:')
            ->assertSee('href="'.route('alerts.keys.domain', ['dominio' => 'empresa.com.br']).'"', false);
        $this->get(route('alerts.keys.domain', ['dominio' => 'empresa.com.br']))->assertOk()->assertSee(', no teste de segurança.');
    }

    private function proven(string $name): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate(['user_id' => $this->user->id, 'domain' => $name, 'source' => 'radar', 'verified_at' => now()]);
    }

    private function key(OwnedDomain $owned, string $kind, string $masked, ?string $name, string $fp, string $source = 'scan'): ExposedKey
    {
        return ExposedKey::query()->create(['owned_domain_id' => $owned->id, 'fingerprint' => str_repeat($fp, 16), 'source' => $source,
            'kind' => $kind, 'masked' => $masked, 'name' => $name, 'locations' => [['host' => $owned->domain, 'path' => '/assets/app.js', 'count' => 2]],
            'occurrences' => 2, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()]);
    }
}
