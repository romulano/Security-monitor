<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\Auth\Reauthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Trocar o e-mail da conta pede a senha atual ou a reautenticação recente: com
 * a sessão sozinha, quem a roubou trocaria o e-mail de entrada e ligaria o
 * próprio GitHub à conta por ele.
 */
class ChangeEmailTest extends TestCase
{
    use RefreshDatabase;

    private function update(User $user, array $data)
    {
        return $this->actingAs($user)->from(route('account.show'))->put(route('account.update'), $data + ['name' => $user->name]);
    }

    public function test_changing_only_the_name_does_not_ask_for_the_password(): void
    {
        $user = User::factory()->create(['email' => 'eu@example.com']);

        $this->update($user, ['name' => 'Outro Nome', 'email' => 'eu@example.com'])->assertSessionHasNoErrors();

        $this->assertSame('Outro Nome', $user->refresh()->name);
    }

    public function test_changing_the_email_without_the_password_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'eu@example.com']);

        $this->update($user, ['email' => 'invasor@example.com'])
            ->assertRedirect(route('account.show'))
            ->assertSessionHasErrorsIn('profile', ['current_password' => 'Para trocar o e-mail, digite a sua senha atual.']);

        $this->assertSame('eu@example.com', $user->refresh()->email);
    }

    public function test_a_wrong_password_does_not_change_the_email(): void
    {
        $user = User::factory()->create(['email' => 'eu@example.com']);

        $this->update($user, ['email' => 'invasor@example.com', 'current_password' => 'errada'])
            ->assertSessionHasErrorsIn('profile', ['current_password' => 'A senha não confere.']);

        $this->assertSame('eu@example.com', $user->refresh()->email);
    }

    public function test_the_right_password_changes_the_email_and_counts_as_a_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'eu@example.com']);

        $this->update($user, ['email' => 'novo@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();

        $this->assertSame('novo@example.com', $user->refresh()->email);
        $this->assertSame('password', Reauthentication::method($user, session()->driver()));
    }

    public function test_a_recent_confirmation_by_github_or_code_is_enough(): void
    {
        $user = User::factory()->create(['email' => 'eu@example.com']);
        $this->withSession(['reauth' => ['user' => $user->id, 'at' => now()->getTimestamp(), 'method' => 'github']]);

        $this->update($user, ['email' => 'novo@example.com'])->assertSessionHasNoErrors();

        $this->assertSame('novo@example.com', $user->refresh()->email);
    }

    public function test_the_password_forms_of_the_account_share_one_attempt_limit(): void
    {
        $user = User::factory()->create(['email' => 'eu@example.com']);

        // Sem o limite compartilhado, o formulário de senha servia para adivinhar a senha atual.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->put(route('account.password'), [
                'current_password' => 'errada', 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123',
            ])->assertSessionHasErrorsIn('password', ['current_password' => 'A senha não confere.']);
        }

        $this->update($user, ['email' => 'novo@example.com', 'current_password' => 'errada'])->assertSessionHasErrorsIn('profile', 'current_password');
        $this->update($user, ['email' => 'novo@example.com', 'current_password' => 'errada'])->assertSessionHasErrorsIn('profile', 'current_password');

        // A sexta tentativa não passa nem com a senha certa, em nenhum dos dois formulários.
        $this->update($user, ['email' => 'novo@example.com', 'current_password' => 'password']);
        $this->assertStringStartsWith('Muitas tentativas.', session('errors')->getBag('profile')->first('current_password'));

        $this->actingAs($user)->put(route('account.password'), [
            'current_password' => 'password', 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123',
        ]);
        $this->assertStringStartsWith('Muitas tentativas.', session('errors')->getBag('password')->first('current_password'));

        $user->refresh();
        $this->assertSame('eu@example.com', $user->email);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_the_account_page_asks_for_the_password_only_to_change_the_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.show'))->assertOk()
            ->assertSee('Só para trocar o e-mail. Trocar o nome não pede a senha.');

        $this->withSession(['reauth' => ['user' => $user->id, 'at' => now()->getTimestamp(), 'method' => 'github']]);

        $this->actingAs($user)->get(route('account.show'))->assertOk()
            ->assertSee('Você confirmou que é você pelo GitHub.')
            ->assertSee('dá para trocar o e-mail sem digitar a senha')
            ->assertDontSee('Só para trocar o e-mail.');
    }
}
