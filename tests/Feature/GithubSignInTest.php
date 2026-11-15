<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Entrar com o GitHub: login de quem já tem conta e cadastro aberto de quem ainda não tem. */
class GithubSignInTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config(['services.github.client_id' => 'Iv1.abc', 'services.github.client_secret' => 'segredo']);
    }

    /** O GitHub de mentira: quem a pessoa é e quais e-mails ela tem. */
    private function fakeGithub(string $id = '4242', string $login = 'maria-dev', ?string $name = 'Maria Dev', ?array $emails = null): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
            'api.github.com/user/emails' => Http::response($emails ?? [
                ['email' => 'maria@outro.com', 'primary' => false, 'verified' => true],
                ['email' => 'Maria@Example.com', 'primary' => true, 'verified' => true],
                ['email' => 'nao@verificado.com', 'primary' => false, 'verified' => false],
            ]),
            'api.github.com/user' => Http::response(['id' => (int) $id, 'login' => $login, 'name' => $name]),
            'api.github.com/applications/*' => Http::response('', 204),
        ]);
    }

    /** Parte do botão e devolve o state que o Security Monitor mandou ao GitHub. */
    private function state(string $url, ?array $post = null): string
    {
        $response = $post !== null ? $this->post($url, $post) : $this->get($url);
        $location = $response->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function comeBack(string $state, string $code = 'codigo')
    {
        return $this->get(route('login.github.callback', ['code' => $code, 'state' => $state]));
    }

    public function test_without_credentials_the_buttons_are_off(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Entrar com o GitHub');
        $this->get(route('register'))->assertOk()->assertDontSee('Cadastrar com o GitHub');
        $this->get(route('login.github'))->assertNotFound();
        $this->post(route('register.github'))->assertNotFound();
        $this->get(route('login.github.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();
    }

    public function test_buttons_show_up_on_login_and_register_but_not_on_the_admin_login(): void
    {
        $this->configure();

        $this->get(route('login'))->assertSee('Entrar com o GitHub')->assertSee(route('login.github'));
        $this->get(route('register'))->assertSee('Cadastrar com o GitHub')->assertSee(route('register.github'));
        $this->get(route('admin.login'))->assertOk()->assertDontSee('GitHub');
    }

    public function test_login_redirects_to_github_with_state_and_minimal_scope(): void
    {
        $this->configure();

        $location = $this->get(route('login.github'))->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame(['Iv1.abc', 'read:user user:email', route('login.github.callback')], [$query['client_id'], $query['scope'], $query['redirect_uri']]);
        $this->assertSame(40, strlen($query['state']));
    }

    public function test_linked_account_logs_in_and_the_token_is_revoked(): void
    {
        $this->configure();
        $this->fakeGithub();
        $user = User::factory()->create(['email' => 'qualquer@example.com']);
        $user->forceFill(['github_id' => '4242'])->save();

        $this->comeBack($this->state(route('login.github')))->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/applications/Iv1.abc/token'));
    }

    public function test_first_login_links_the_account_by_the_verified_email(): void
    {
        $this->configure();
        $this->fakeGithub();
        $user = User::factory()->create(['email' => 'maria@example.com']);

        $this->comeBack($this->state(route('login.github')))->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame('4242', $user->github_id);
        $this->assertSame('maria-dev', $user->github_login);
    }

    public function test_an_email_that_github_did_not_verify_does_not_link_anything(): void
    {
        $this->configure();
        $this->fakeGithub(emails: [['email' => 'maria@example.com', 'primary' => true, 'verified' => false]]);
        User::factory()->create(['email' => 'maria@example.com']);

        $this->comeBack($this->state(route('login.github')))->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_an_account_tied_to_another_github_is_not_taken_over_by_email(): void
    {
        $this->configure();
        $this->fakeGithub();
        $owner = User::factory()->create(['email' => 'maria@example.com']);
        $owner->forceFill(['github_id' => '999'])->save();

        $this->comeBack($this->state(route('login.github')))->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame('999', $owner->refresh()->github_id);
    }

    public function test_github_without_a_secmon_account_does_not_register_anyone(): void
    {
        $this->configure();
        $this->fakeGithub();

        $this->comeBack($this->state(route('login.github')))->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_never_enters_through_github(): void
    {
        $this->configure();
        $this->fakeGithub();
        User::factory()->create(['email' => 'maria@example.com', 'is_admin' => true]);

        $this->comeBack($this->state(route('login.github')))->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_wrong_state_and_cancelled_authorization_are_refused(): void
    {
        $this->configure();
        $this->fakeGithub();
        User::factory()->create(['email' => 'maria@example.com']);

        $this->state(route('login.github'));
        $this->comeBack('outro-state')->assertSessionHas('error');
        $this->assertGuest();

        // O state vale uma vez: o certo também já foi gasto.
        $this->comeBack('qualquer')->assertSessionHas('error');

        $state = $this->state(route('login.github'));
        $this->get(route('login.github.callback', ['error' => 'access_denied', 'state' => $state]))->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_github_without_verified_email_is_refused(): void
    {
        $this->configure();
        $this->fakeGithub(emails: []);

        $this->comeBack($this->state(route('login.github')))->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_register_goes_straight_to_github_and_ignores_an_old_invite_field(): void
    {
        $this->configure();

        // Formulário antigo aberto num navegador ainda pode mandar o campo do convite: é ignorado.
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $this->post(route('register.github'), ['invite' => str_repeat('0', 32)])->headers->get('Location'));
        $this->assertArrayNotHasKey('invite', session('github_oauth'));
    }

    public function test_register_with_github_creates_a_confirmed_account(): void
    {
        $this->configure();
        $this->fakeGithub();

        $this->comeBack($this->state(route('register.github'), []))->assertRedirect(route('account.show'))->assertSessionHas('initial_password');

        $user = User::query()->where('email', 'maria@example.com')->firstOrFail();
        $this->assertSame('Maria Dev', $user->name);
        $this->assertSame('4242', $user->github_id);
        $this->assertSame('maria-dev', $user->github_login);
        $this->assertFalse($user->is_admin);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at, 'o GitHub só entrega e-mail verificado');
        $this->assertDatabaseCount('email_messages', 0);
        $this->get(route('alerts.index'))->assertOk();
    }

    public function test_the_initial_password_of_a_github_account_also_works_at_login(): void
    {
        $this->configure();
        $this->fakeGithub();

        $this->comeBack($this->state(route('register.github'), []));
        $password = session('initial_password');
        auth()->logout();

        $this->post(route('login'), ['email' => 'maria@example.com', 'password' => $password])->assertRedirect(route('account.show'));
    }

    public function test_register_name_falls_back_to_the_login(): void
    {
        $this->configure();
        $this->fakeGithub(name: null);

        $this->comeBack($this->state(route('register.github'), []));

        $this->assertSame('maria-dev', User::query()->where('email', 'maria@example.com')->value('name'));
    }

    public function test_register_refuses_an_email_that_already_has_an_account(): void
    {
        $this->configure();
        $this->fakeGithub();
        User::factory()->create(['email' => 'maria@example.com']);

        $this->comeBack($this->state(route('register.github'), []))
            ->assertRedirect(route('login'))->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['github_id' => '4242']);
        $this->assertGuest();
    }

    public function test_register_refuses_a_github_that_already_has_an_account(): void
    {
        $this->configure();
        $this->fakeGithub();
        User::factory()->create(['email' => 'velha@example.com'])->forceFill(['github_id' => '4242'])->save();

        $this->comeBack($this->state(route('register.github'), []))->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'maria@example.com']);
    }

    public function test_a_removed_user_cannot_come_back_through_github(): void
    {
        $this->configure();
        $this->fakeGithub();
        $this->comeBack($this->state(route('register.github'), []));
        auth()->logout();
        User::query()->where('github_id', '4242')->delete();

        $this->comeBack($this->state(route('login.github')))->assertSessionHas('error');

        $this->assertGuest();
    }
}
