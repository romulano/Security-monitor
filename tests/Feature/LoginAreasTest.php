<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginAreasTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_user_logs_in_at_login_and_lands_on_account(): void
    {
        $user = User::factory()->create(['email' => 'u@example.com']);

        $this->post(route('login'), ['email' => 'u@example.com', 'password' => 'password'])
            ->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_cannot_log_in_at_common_login(): void
    {
        User::factory()->create(['email' => 'adm@example.com', 'is_admin' => true]);

        $this->post(route('login'), ['email' => 'adm@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);

        $this->assertGuest();
    }

    public function test_admin_logs_in_at_admin_login(): void
    {
        $admin = User::factory()->create(['email' => 'adm@example.com', 'is_admin' => true]);

        $this->post(route('admin.login'), ['email' => 'adm@example.com', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_common_user_cannot_log_in_at_admin_login(): void
    {
        User::factory()->create(['email' => 'u@example.com']);

        $this->post(route('admin.login'), ['email' => 'u@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);

        $this->assertGuest();
    }

    public function test_login_urls(): void
    {
        $this->assertSame(url('/login'), route('login'));
        $this->assertSame(url('/admin/login'), route('admin.login'));

        $this->get('/login')->assertOk()->assertSee('Criar conta');
        $this->get('/admin/login')->assertOk()->assertSee('Área administrativa');
    }

    public function test_guests_are_sent_to_the_login_of_each_area(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/admin/fontes')->assertRedirect(route('admin.login'));
        $this->get('/conta')->assertRedirect(route('login'));
    }

    public function test_logged_in_users_skip_the_login_pages(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/login')->assertRedirect(route('admin.dashboard'));

        $this->actingAs(User::factory()->create())
            ->get('/login')->assertRedirect(route('account.show'));
    }

    public function test_common_user_has_no_access_to_admin(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_account_updates_name_and_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('account.update'), ['name' => 'Novo Nome', 'email' => 'novo@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Novo Nome', $user->name);
        $this->assertSame('novo@example.com', $user->email);
    }

    public function test_account_rejects_email_of_another_user(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->actingAs($user)
            ->put(route('account.update'), ['name' => 'Eu', 'email' => 'taken@example.com'])
            ->assertSessionHasErrorsIn('profile', 'email');

        // O próprio e-mail continua válido ao salvar só o nome.
        $this->actingAs($user)
            ->put(route('account.update'), ['name' => 'Eu', 'email' => 'me@example.com'])
            ->assertSessionHasNoErrors();
    }

    public function test_account_cannot_promote_itself_to_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('account.update'), ['name' => 'Eu', 'email' => $user->email, 'is_admin' => 1]);

        $this->assertFalse($user->refresh()->is_admin);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('account.password'), [
                'current_password' => 'errada',
                'password' => 'nova-senha-123',
                'password_confirmation' => 'nova-senha-123',
            ])
            ->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        $this->actingAs($user)
            ->put(route('account.password'), [
                'current_password' => 'password',
                'password' => 'nova-senha-123',
                'password_confirmation' => 'nova-senha-123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nova-senha-123', $user->refresh()->password));
    }

    public function test_password_change_requires_confirmation_and_minimum_length(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('account.password'), [
                'current_password' => 'password',
                'password' => 'curta',
                'password_confirmation' => 'outra',
            ])
            ->assertSessionHasErrorsIn('password', 'password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
