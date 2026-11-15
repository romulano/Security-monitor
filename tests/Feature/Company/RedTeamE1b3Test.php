<?php

namespace Tests\Feature\Company;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Red Team da E1b3 (um e-mail, uma conta, sem diferença de caixa). */
class RedTeamE1b3Test extends TestCase
{
    use RefreshDatabase;

    private function signUp(string $email)
    {
        return $this->post(route('register'), ['name' => 'Érica', 'email' => $email, 'password' => 'minha-senha-123', 'password_confirmation' => 'minha-senha-123']);
    }

    // F1 — e-mail com maiúscula acentuada: o PHP (mb_strtolower) e o banco (lower) precisam concordar, senão a dona não entra.
    public function test_rt_f1_quem_tem_maiuscula_acentuada_entra_com_o_proprio_email(): void
    {
        $this->signUp('ÉRICA@acme.com')->assertSessionHasNoErrors();
        $user = User::query()->sole();
        $user->forceFill(['email_verified_at' => now()])->save();
        auth()->logout();

        $this->post(route('login'), ['email' => 'ÉRICA@acme.com', 'password' => 'minha-senha-123']);

        $this->assertAuthenticatedAs($user);
    }

    // F2 — e a mesma pessoa em minúsculas não abre uma segunda conta.
    public function test_rt_f2_a_mesma_caixa_acentuada_nao_abre_outra_conta(): void
    {
        $this->signUp('ÉRICA@acme.com');
        auth()->logout();

        $this->signUp('érica@acme.com');

        $this->assertSame(1, User::query()->count(), 'Duas contas para ÉRICA@ e érica@.');
    }

    // Evidência — no ASCII, cadastro, login, troca de e-mail e índice concordam.
    public function test_evidencia_ascii(): void
    {
        $this->signUp('Ana@Acme.com')->assertSessionHasNoErrors();
        $ana = User::query()->sole();
        $ana->forceFill(['email_verified_at' => now()])->save();
        auth()->logout();
        $this->signUp('ana@ACME.com')->assertSessionHasErrors('email');

        $this->post(route('login'), ['email' => 'ANA@acme.COM', 'password' => 'minha-senha-123']);
        $this->assertAuthenticatedAs($ana);

        $beto = User::factory()->create(['email' => 'beto@acme.com']);
        $this->actingAs($beto)->put(route('account.update'), ['name' => 'Beto', 'email' => 'ANA@acme.com', 'current_password' => 'password'])->assertSessionHasErrors('email', null, 'profile');
        $this->assertSame('beto@acme.com', $beto->fresh()->email);
    }

    // F3 — o İ turco: mb_strtolower dá "i̇" (i + ponto), o lower() do banco pode dar "i". A dona não entra com o próprio e-mail.
    public function test_rt_f3_i_maiusculo_com_ponto_entra_com_o_proprio_email(): void
    {
        $this->signUp('İPEK@acme.com')->assertSessionHasNoErrors();
        $user = User::query()->sole();
        $user->forceFill(['email_verified_at' => now()])->save();
        auth()->logout();

        $this->post(route('login'), ['email' => 'İPEK@acme.com', 'password' => 'minha-senha-123']);

        $this->assertAuthenticatedAs($user);
    }
}
