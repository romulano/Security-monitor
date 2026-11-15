<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O cadastro aberto, sem convite (o captcha está no TurnstileTest e a confirmação
 * do e-mail no AccountVerificationTest), e o fim do recurso de convite: a tela do
 * admin virou só Usuários e o link antigo com ?convite= abre o cadastro normal.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function signUp(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'password' => 'minha-senha-123',
            'password_confirmation' => 'minha-senha-123',
        ];
    }

    public function test_the_person_picks_the_password_at_registration(): void
    {
        $response = $this->post(route('register'), $this->signUp());

        $response->assertRedirect(route('verification.notice'));
        $response->assertSessionMissing('initial_password');

        $user = User::query()->where('email', 'maria@example.com')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('minha-senha-123', $user->password));
        $this->assertDatabaseCount('invites', 0);

        // A senha digitada no cadastro é a que entra pelo /login.
        Auth::logout();

        $this->post(route('login'), ['email' => 'maria@example.com', 'password' => 'minha-senha-123'])
            ->assertRedirect(route('account.show'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_register_form_asks_for_the_password_twice_and_no_invite(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertDontSee('name="invite"', false);
    }

    public function test_registration_refuses_a_missing_short_or_unconfirmed_password(): void
    {
        $this->post(route('register'), $this->signUp(['password' => null, 'password_confirmation' => null]))
            ->assertSessionHasErrors(['password' => 'Informe a senha.']);

        $this->post(route('register'), $this->signUp(['password' => 'curta', 'password_confirmation' => 'curta']))
            ->assertSessionHasErrors(['password' => 'A senha precisa ter pelo menos 8 caracteres.']);

        $this->post(route('register'), $this->signUp(['password_confirmation' => 'outra-coisa-123']))
            ->assertSessionHasErrors(['password' => 'A confirmação não bate com a senha.']);

        $this->assertDatabaseMissing('users', ['email' => 'maria@example.com']);
        $this->assertGuest();
    }

    public function test_registration_cannot_create_an_admin(): void
    {
        $this->post(route('register'), $this->signUp(['email' => 'a@example.com', 'is_admin' => 1]));

        $this->assertFalse(User::query()->where('email', 'a@example.com')->firstOrFail()->is_admin);
    }

    /** O link que o admin mandava (/cadastro?convite=...) ainda circula: abre o cadastro e o código é ignorado. */
    public function test_an_old_invite_link_opens_the_register_and_signs_up_without_error(): void
    {
        $this->get(route('register', ['convite' => 'abc123def456']))
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertDontSee('abc123def456');

        $this->post(route('register'), $this->signUp(['invite' => 'abc123def456']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs(User::query()->where('email', 'maria@example.com')->sole());
        $this->assertDatabaseCount('invites', 0);
    }

    public function test_the_admin_page_lists_the_users_without_invites(): void
    {
        User::factory()->create(['name' => 'Maria Souza', 'email' => 'maria@example.com']);

        $this->actingAs($this->admin())->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Usuários')
            ->assertSee('maria@example.com')
            ->assertSee('O cadastro é aberto')
            ->assertDontSee('Gerar convite')
            ->assertDontSee('Convites')
            ->assertDontSee('só se cadastra com convite');

        $this->assertSame('/admin/usuarios', parse_url(route('admin.users'), PHP_URL_PATH));
    }

    public function test_the_old_address_redirects_to_the_users_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/convites')->assertStatus(301)->assertRedirect('/admin/usuarios');
    }

    public function test_creating_or_revoking_an_invite_no_longer_exists(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/convites')->assertStatus(405);
        $this->actingAs($admin)->delete('/admin/convites/1')->assertNotFound();
        $this->assertDatabaseCount('invites', 0);

        foreach (['admin.invites', 'admin.invites.store', 'admin.invites.destroy'] as $name) {
            $this->assertFalse(app('router')->has($name), $name);
        }
    }

    public function test_guest_and_common_user_do_not_reach_the_users_page(): void
    {
        $this->get(route('admin.users'))->assertRedirect(route('admin.login'));
        $this->get('/admin/convites')->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create())->get(route('admin.users'))->assertForbidden();
    }
}
