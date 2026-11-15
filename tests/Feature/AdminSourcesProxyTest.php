<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin → Fontes: o proxy de uma fonte. Grava criptografado, aceita só
 * http/https/socks5/socks5h com host e porta, editar em branco mantém o atual
 * e a senha nunca volta para a tela (só esquema, host e porta).
 */
class AdminSourcesProxyTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = 'socks5h://usuario:s3nh4-secreta@1.2.3.4:9611';

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function store(array $data = []): TestResponse
    {
        return $this->actingAs($this->admin())->from(route('admin.sources'))->post(route('admin.sources.store'), $data + [
            'name' => 'Security Online',
            'feed_url' => 'https://securityonline.info/feed/',
            'host' => null,
            'interval_minutes' => 60,
            'max_items' => null,
        ]);
    }

    private function update(Source $source, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin())->from(route('admin.sources'))->put(route('admin.sources.update', $source), $data + [
            'name' => 'Security Online',
            'interval_minutes' => 60,
            'max_items' => null,
        ]);
    }

    private function source(?string $proxy = self::PROXY): Source
    {
        return Source::create(['name' => 'Security Online', 'host' => 'securityonline.info', 'feed_url' => 'https://securityonline.info/feed/', 'proxy_url' => $proxy]);
    }

    public function test_a_source_registered_with_a_proxy_keeps_it_encrypted(): void
    {
        $this->store(['proxy_url' => self::PROXY])->assertRedirect(route('admin.sources'))->assertSessionHasNoErrors();

        $this->assertSame(self::PROXY, Source::query()->sole()->proxy_url);
        $raw = (string) DB::table('sources')->value('proxy_url');
        $this->assertNotSame(self::PROXY, $raw);
        $this->assertStringNotContainsString('s3nh4-secreta', $raw);
    }

    public function test_a_source_registered_without_a_proxy_goes_out_directly(): void
    {
        $this->store(['proxy_url' => null])->assertSessionHasNoErrors();

        $this->assertNull(Source::query()->sole()->proxy_url);
    }

    public function test_an_invalid_proxy_comes_back_with_an_error_and_is_not_flashed(): void
    {
        foreach (['ftp://a:b@1.2.3.4:21', 'socks5h://a:b@1.2.3.4', 'http://1.2.3.4:70000', 'socks5h://:9611', '1.2.3.4:9611', 'http://1.2.3.4:8080/caminho'] as $proxy) {
            $this->store(['proxy_url' => $proxy])->assertSessionHasErrors('proxy_url')->assertSessionMissing('_old_input.proxy_url');
        }

        $this->assertSame(0, Source::query()->count());
    }

    public function test_every_accepted_scheme_passes(): void
    {
        foreach (['http://1.2.3.4:3128', 'https://u:p@proxy.test:443', 'socks5://1.2.3.4:1080', 'socks5h://u:p@1.2.3.4:9611'] as $i => $proxy) {
            $this->store(['proxy_url' => $proxy, 'feed_url' => "https://feed{$i}.test/rss"])->assertSessionHasNoErrors();
        }

        $this->assertSame(4, Source::query()->whereNotNull('proxy_url')->count());
    }

    public function test_editing_with_the_field_blank_keeps_the_proxy_and_remove_proxy_clears_it(): void
    {
        $source = $this->source();

        $this->update($source, ['name' => 'Outro nome', 'proxy_url' => null])->assertSessionHasNoErrors();
        $this->assertSame(['Outro nome', self::PROXY], [$source->refresh()->name, $source->proxy_url]);

        $this->update($source, ['proxy_url' => 'http://5.6.7.8:3128'])->assertSessionHasNoErrors();
        $this->assertSame('http://5.6.7.8:3128', $source->refresh()->proxy_url);

        $this->update($source, ['proxy_url' => 'ftp://5.6.7.8:21'])->assertSessionHasErrors('proxy_url');
        $this->assertSame('http://5.6.7.8:3128', $source->refresh()->proxy_url);

        $this->update($source, ['remove_proxy' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($source->refresh()->proxy_url);
    }

    public function test_the_page_shows_host_and_port_but_never_the_password(): void
    {
        $this->source();

        $this->actingAs($this->admin())->get(route('admin.sources'))
            ->assertOk()
            ->assertSee('via proxy socks5h://1.2.3.4:9611')
            ->assertSee('Remover proxy')
            ->assertDontSee('s3nh4-secreta')
            ->assertDontSee('usuario:s3nh4');
    }

    public function test_a_source_without_a_proxy_has_no_badge_nor_remove_option(): void
    {
        $this->source(null);

        $this->actingAs($this->admin())->get(route('admin.sources'))
            ->assertOk()
            ->assertDontSee('via proxy')
            ->assertDontSee('Remover proxy');
    }
}
