<?php

namespace Tests\Feature\Keys;

use App\Jobs\ScanSiteKeys;
use App\Models\ExposedKey;
use App\Models\KeySite;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Chaves expostas com lista própria: adicionar um domínio direto lá e remover (o cadastrado lá ou o herdado). */
class KeyDomainsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_adding_lists_the_domain_without_scanning_and_twice_is_harmless(): void
    {
        $this->post(route('alerts.keys.store'), ['dominio' => ' https://Loja.com.br/ '])
            ->assertRedirect(route('alerts.keys'))
            ->assertSessionHas('status', 'loja.com.br adicionado. Clique em “Ativar e varrer” para a primeira varredura.');

        $owned = OwnedDomain::query()->sole();
        $this->assertSame(['loja.com.br', 'keys', OwnedDomain::KEYS_ADDED, $this->user->id], [$owned->domain, $owned->source, $owned->keys_listing, $owned->user_id]);
        $this->assertTrue($owned->isVerified());
        Queue::assertNotPushed(ScanSiteKeys::class);

        $this->get(route('alerts.keys'))->assertOk()->assertSee('loja.com.br')->assertSee('Cadastrado em Chaves expostas.')->assertSee('Ativar e varrer');

        $this->post(route('alerts.keys.store'), ['dominio' => 'loja.com.br'])->assertSessionHas('status', 'loja.com.br já estava na lista.');
        $this->assertSame(1, OwnedDomain::query()->count());
    }

    public function test_an_invalid_domain_is_refused(): void
    {
        foreach (['', 'nao é domínio', 'localhost'] as $typed) {
            $this->from(route('alerts.keys'))->post(route('alerts.keys.store'), ['dominio' => $typed])->assertSessionHasErrors('dominio');
        }

        $this->assertSame(0, OwnedDomain::query()->count());
    }

    public function test_removing_a_domain_added_here_takes_the_scan_and_the_keys_with_it(): void
    {
        $this->post(route('alerts.keys.store'), ['dominio' => 'loja.com.br']);
        $owned = OwnedDomain::query()->sole();
        KeySite::query()->forceCreate(['owned_domain_id' => $owned->id, 'user_id' => $this->user->id, 'status' => 'active']);
        $this->key($owned);

        $this->delete(route('alerts.keys.destroy', ['dominio' => 'loja.com.br']))
            ->assertRedirect(route('alerts.keys'))
            ->assertSessionHas('status', 'loja.com.br removido de Chaves expostas.');

        $this->assertSame([0, 0, 0], [OwnedDomain::query()->count(), KeySite::query()->count(), ExposedKey::query()->count()]);
        $this->get(route('alerts.keys'))->assertDontSee(route('alerts.keys.destroy', ['dominio' => 'loja.com.br']));
    }

    public function test_removing_an_inherited_domain_only_hides_it_here_and_adding_brings_it_back(): void
    {
        $radar = MonitoredDomain::query()->create(['user_id' => $this->user->id, 'domain' => 'loja.com.br']);
        $owned = OwnedDomain::query()->forceCreate(['user_id' => $this->user->id, 'domain' => 'loja.com.br', 'source' => 'radar']);
        KeySite::query()->forceCreate(['owned_domain_id' => $owned->id, 'user_id' => $this->user->id, 'status' => 'active']);
        $this->key($owned);

        $this->delete(route('alerts.keys.destroy', ['dominio' => 'loja.com.br']))->assertRedirect(route('alerts.keys'));

        $this->assertSame(OwnedDomain::KEYS_REMOVED, $owned->refresh()->keys_listing);
        $this->assertNotNull($radar->fresh(), 'o Radar continua');
        $this->assertSame([0, 0], [KeySite::query()->count(), ExposedKey::query()->count()]);
        $this->get(route('alerts.keys'))->assertDontSee(route('alerts.keys.destroy', ['dominio' => 'loja.com.br']));
        $this->get(route('alerts.keys.domain', ['dominio' => 'loja.com.br']))->assertNotFound();

        $this->post(route('alerts.keys.store'), ['dominio' => 'loja.com.br'])->assertSessionHas('status', 'loja.com.br adicionado. Clique em “Ativar e varrer” para a primeira varredura.');
        $this->assertSame(OwnedDomain::KEYS_ADDED, $owned->refresh()->keys_listing);
        $this->get(route('alerts.keys'))->assertSee('loja.com.br');
    }

    public function test_a_domain_added_here_stays_when_the_radar_of_the_same_name_is_deleted(): void
    {
        $radar = MonitoredDomain::query()->create(['user_id' => $this->user->id, 'domain' => 'loja.com.br']);
        OwnedDomain::query()->forceCreate(['user_id' => $this->user->id, 'domain' => 'loja.com.br', 'source' => 'radar']);
        $this->post(route('alerts.keys.store'), ['dominio' => 'loja.com.br']);

        $radar->delete();

        $this->assertSame(1, OwnedDomain::query()->where('domain', 'loja.com.br')->count());
        $this->get(route('alerts.keys'))->assertSee('loja.com.br');
    }

    public function test_a_running_scan_holds_the_removal(): void
    {
        $this->post(route('alerts.keys.store'), ['dominio' => 'loja.com.br']);
        $owned = OwnedDomain::query()->sole();
        KeySite::query()->forceCreate(['owned_domain_id' => $owned->id, 'user_id' => $this->user->id, 'status' => 'active', 'scan_status' => 'running']);

        $this->from(route('alerts.keys'))->delete(route('alerts.keys.destroy', ['dominio' => 'loja.com.br']))
            ->assertSessionHas('error', 'A varredura de loja.com.br está rodando agora. Remova quando ela terminar.');

        $this->assertSame([1, 1], [OwnedDomain::query()->count(), KeySite::query()->count()]);
    }

    private function key(OwnedDomain $owned): ExposedKey
    {
        return ExposedKey::query()->create(['owned_domain_id' => $owned->id, 'fingerprint' => str_repeat('ab', 16), 'source' => 'scan',
            'kind' => 'aws', 'masked' => 'AKIA••••XYZ0 (20 caracteres)', 'name' => null, 'locations' => [['host' => $owned->domain, 'path' => '/app.js', 'count' => 1]],
            'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }
}
