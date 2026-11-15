<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\Auth\Reauthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Reautenticação recente: o middleware `reauth` numa ação sensível. Nenhuma
 * rota de produção usa o `reauth` ainda (a stack de testes vai usar), então os
 * testes registram uma ação de mentira.
 */
class ReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ACTION = '/_teste/acao-sensivel';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'reauth'])->post(self::ACTION, fn () => 'feito');
        Route::middleware(['web', 'reauth'])->post('/_teste/sem-login', fn () => 'feito');
    }

    private function act(User $user, array $data = [])
    {
        return $this->actingAs($user)->from('/conta')->post(self::ACTION, $data);
    }

    public function test_without_a_recent_confirmation_the_action_does_not_run(): void
    {
        $user = User::factory()->create();

        $this->act($user)
            ->assertRedirect('/conta')
            ->assertSessionHasErrorsIn('reauth', ['reauth_password' => 'Digite a sua senha para confirmar que é você.']);

        $this->assertNull(Reauthentication::method($user, session()->driver()));
    }

    public function test_the_password_sent_with_the_form_confirms_and_the_action_runs(): void
    {
        $user = User::factory()->create();

        $this->act($user, ['reauth_password' => 'password'])->assertOk()->assertSee('feito');
        $this->assertSame('password', Reauthentication::method($user, session()->driver()));

        // Dentro dos 10 minutos, a próxima ação passa sem a senha.
        $this->act($user)->assertOk()->assertSee('feito');
    }

    public function test_a_wrong_password_is_refused_and_five_errors_lock_it_for_five_minutes(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->act($user, ['reauth_password' => 'errada'])
                ->assertSessionHasErrorsIn('reauth', ['reauth_password' => 'A senha não confere.']);
        }

        // A sexta não confere nem a senha certa.
        $this->act($user, ['reauth_password' => 'password'])->assertRedirect('/conta');
        $this->assertStringStartsWith('Muitas tentativas.', session('errors')->getBag('reauth')->first('reauth_password'));
        $this->assertFalse(Reauthentication::recent($user, session()->driver()));

        $this->travel(301)->seconds();
        $this->act($user, ['reauth_password' => 'password'])->assertOk();
    }

    public function test_the_confirmation_lasts_ten_minutes(): void
    {
        $user = User::factory()->create();
        $this->act($user, ['reauth_password' => 'password'])->assertOk();

        $this->travel(9)->minutes();
        $this->act($user)->assertOk();

        $this->travel(1)->minutes();
        $this->act($user)->assertSessionHasErrorsIn('reauth', 'reauth_password');
    }

    public function test_a_confirmation_of_another_user_does_not_count(): void
    {
        $other = User::factory()->create();
        $user = User::factory()->create();

        $this->withSession(['reauth' => ['user' => $other->id, 'at' => now()->getTimestamp(), 'method' => 'password']]);

        $this->act($user)->assertSessionHasErrorsIn('reauth', 'reauth_password');
    }

    public function test_confirming_gives_the_session_a_new_id(): void
    {
        $user = User::factory()->create();
        $session = session()->driver();
        $session->start();
        $before = $session->getId();

        app(Reauthentication::class)->confirmPassword($user, 'password', $session);

        $this->assertNotSame($before, $session->getId());
        $this->assertTrue(Reauthentication::recent($user, $session));
    }

    public function test_the_password_never_comes_back_in_the_old_input(): void
    {
        $user = User::factory()->create();

        $this->act($user, ['reauth_password' => 'errada', 'nota' => 'continua']);

        $this->assertNull(session()->getOldInput('reauth_password'));
        $this->assertSame('continua', session()->getOldInput('nota'));
    }

    public function test_without_login_the_middleware_sends_to_the_login(): void
    {
        $this->post('/_teste/sem-login', ['reauth_password' => 'password'])->assertRedirect(route('login'));
    }
}
