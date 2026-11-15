<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    protected function setUp(): void
    {
        parent::setUp();

        // Chaves de teste públicas da Cloudflare; a resposta é sempre falsificada aqui.
        config([
            'services.turnstile.site_key' => '1x00000000000000000000AA',
            'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
        ]);
    }

    private function cloudflareSays(bool $success, string $action = 'login'): void
    {
        Http::fake([self::VERIFY => Http::response([
            'success' => $success,
            'action' => $action,
            'error-codes' => $success ? [] : ['invalid-input-response'],
        ])]);
    }

    public function test_login_pages_show_the_widget(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('data-sitekey="1x00000000000000000000AA"', false)
            ->assertSee('data-action="login"', false)
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false);

        $this->get('/admin/login')->assertOk()->assertSee('data-action="admin-login"', false);
        $this->get('/cadastro')->assertOk()->assertSee('data-action="cadastro"', false);
    }

    public function test_without_keys_the_widget_is_off_and_login_works(): void
    {
        config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
        Http::fake();
        $user = User::factory()->create(['email' => 'u@example.com']);

        $this->get('/login')->assertDontSee('cf-turnstile', false);
        $this->post(route('login'), ['email' => 'u@example.com', 'password' => 'password'])
            ->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();
    }

    public function test_login_without_token_is_refused_before_checking_the_password(): void
    {
        Http::fake();
        User::factory()->create(['email' => 'u@example.com']);

        $this->post(route('login'), ['email' => 'u@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_login_with_valid_token_goes_through(): void
    {
        $this->cloudflareSays(true, 'login');
        $user = User::factory()->create(['email' => 'u@example.com']);

        $this->post(route('login'), [
            'email' => 'u@example.com',
            'password' => 'password',
            'cf-turnstile-response' => 'token-ok',
        ])->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn (Request $r) => $r->url() === self::VERIFY
            && $r['secret'] === '1x0000000000000000000000000000000AA'
            && $r['response'] === 'token-ok'
            && $r['remoteip'] === '127.0.0.1');
    }

    public function test_token_rejected_by_cloudflare_blocks_the_login(): void
    {
        $this->cloudflareSays(false);
        User::factory()->create(['email' => 'u@example.com']);

        $this->post(route('login'), [
            'email' => 'u@example.com',
            'password' => 'password',
            'cf-turnstile-response' => 'token-ruim',
        ])->assertSessionHasErrors(['cf-turnstile-response' => 'Não foi possível confirmar que você não é um robô. Tente de novo.']);

        $this->assertGuest();
    }

    public function test_token_from_another_screen_is_refused(): void
    {
        $this->cloudflareSays(true, 'cadastro');
        User::factory()->create(['email' => 'adm@example.com', 'is_admin' => true]);

        $this->post(route('admin.login'), [
            'email' => 'adm@example.com',
            'password' => 'password',
            'cf-turnstile-response' => 'token-do-cadastro',
        ])->assertSessionHasErrors('cf-turnstile-response');

        $this->assertGuest();
    }

    public function test_site_key_without_secret_keeps_the_door_closed(): void
    {
        config(['services.turnstile.secret_key' => null]);
        Http::fake();
        User::factory()->create(['email' => 'u@example.com']);

        $this->get('/login')->assertSee('cf-turnstile', false);
        $this->post(route('login'), [
            'email' => 'u@example.com',
            'password' => 'password',
            'cf-turnstile-response' => 'token',
        ])->assertSessionHasErrors(['cf-turnstile-response' => 'O login está temporariamente indisponível. Tente de novo mais tarde.']);

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_cloudflare_offline_keeps_the_door_closed(): void
    {
        Http::fake([self::VERIFY => Http::failedConnection()]);
        User::factory()->create(['email' => 'adm@example.com', 'is_admin' => true]);

        $this->post(route('admin.login'), [
            'email' => 'adm@example.com',
            'password' => 'password',
            'cf-turnstile-response' => 'token',
        ])->assertSessionHasErrors(['cf-turnstile-response' => 'Não conseguimos falar com a Cloudflare para confirmar o captcha. Tente de novo em instantes.']);

        $this->assertGuest();
    }

    public function test_register_requires_a_valid_token(): void
    {
        $this->cloudflareSays(true, 'cadastro');

        $this->post(route('register'), ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'senha-da-ana-1', 'password_confirmation' => 'senha-da-ana-1'])
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
        Http::assertNothingSent();

        $this->post(route('register'), [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'senha-da-ana-1',
            'password_confirmation' => 'senha-da-ana-1',
            'cf-turnstile-response' => 'token-ok',
        ])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('users', ['email' => 'ana@example.com']);
    }
}
