<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Os dois freios do login: a trava por conta (5 erros em 5 minutos) e o limite de requisições por IP e rota. */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login:maria@example.com|127.0.0.1');
    }

    private function wrong(string $email = 'maria@example.com', string $route = 'login'): TestResponse
    {
        return $this->post(route($route), ['email' => $email, 'password' => 'errada']);
    }

    public function test_five_wrong_passwords_lock_the_account_even_for_the_right_one(): void
    {
        User::factory()->create(['email' => 'maria@example.com']);

        foreach (range(1, 5) as $_) {
            $this->wrong()->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);
        }

        $this->post(route('login'), ['email' => 'maria@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('email'));
    }

    public function test_the_lock_is_per_account_and_per_ip_so_another_email_is_not_affected(): void
    {
        User::factory()->create(['email' => 'maria@example.com']);
        User::factory()->create(['email' => 'joao@example.com']);

        foreach (range(1, 5) as $_) {
            $this->wrong();
        }

        $this->post(route('login'), ['email' => 'joao@example.com', 'password' => 'password'])
            ->assertRedirect(route('account.show'));
    }

    public function test_a_successful_login_clears_the_failures(): void
    {
        User::factory()->create(['email' => 'maria@example.com']);

        foreach (range(1, 4) as $_) {
            $this->wrong();
        }
        $this->post(route('login'), ['email' => 'maria@example.com', 'password' => 'password'])->assertRedirect(route('account.show'));
        $this->post(route('logout'));

        foreach (range(1, 4) as $_) {
            $this->wrong()->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);
        }
    }

    public function test_the_login_page_answers_429_after_ten_requests_a_minute_whatever_the_email(): void
    {
        $this->exhaust('login');
    }

    public function test_the_admin_login_page_is_limited_the_same_way(): void
    {
        $this->exhaust('admin.login');
    }

    /**
     * Sem login, o contador é por IP: entrar e cadastrar dividem o `entrada`, as 3 rotas do GitHub o
     * `github-oauth`, e o login do admin fica no contador do IP. Um cenário por teste.
     */
    private function exhaust(string $route): void
    {
        foreach (range(1, 10) as $n) {
            $this->wrong("alguem{$n}@example.com", $route)->assertSessionHasErrors('email');
        }

        $this->wrong('alguem11@example.com', $route)->assertStatus(429);
    }

    public function test_the_sign_up_form_is_limited_the_same_way(): void
    {
        foreach (range(1, 10) as $_) {
            $this->post(route('register'), [])->assertSessionHasErrors();
        }

        $this->post(route('register'), [])->assertStatus(429);
    }

    public function test_using_up_the_login_does_not_lock_the_github_sign_in(): void
    {
        // Item 10 do Blue Team: o abuso do login numa rede (NAT) não tranca o login com o GitHub.
        config(['services.github.client_id' => 'Iv1.abc', 'services.github.client_secret' => 'segredo']);
        $this->exhaust('login');

        // O cadastro divide o contador com o login...
        $this->post(route('register'), [])->assertStatus(429);

        // ...e o GitHub tem o dele: o botão ainda leva ao GitHub.
        $location = $this->get(route('login.github'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
    }

    public function test_the_three_github_routes_share_one_limit_of_their_own(): void
    {
        config(['services.github.client_id' => 'Iv1.abc', 'services.github.client_secret' => 'segredo']);

        foreach (range(1, 10) as $_) {
            $this->get(route('login.github'))->assertRedirect();
        }

        // As 3 rotas do GitHub somam juntas: quem distribui entre elas não ganha um contador por rota.
        $this->get(route('login.github'))->assertStatus(429);
        $this->get(route('login.github.callback', ['code' => 'x', 'state' => 'y']))->assertStatus(429);
        $this->post(route('register.github'))->assertStatus(429);

        // O login com a senha continua.
        $this->wrong()->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);
    }
}
