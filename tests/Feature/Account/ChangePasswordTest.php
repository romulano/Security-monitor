<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Trocar a senha derruba as outras sessões e o "continuar conectado" dos
 * outros aparelhos, como na troca pelo admin: é o que a pessoa faz quando
 * desconfia que alguém entrou na conta.
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function sessions(User $user, User $other): void
    {
        config(['session.driver' => 'database']);

        foreach (['notebook', 'celular-roubado'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
        DB::table('sessions')->insert(['id' => 'de-outro', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);
    }

    private function change(User $user, string $current)
    {
        return $this->actingAs($user)->put(route('account.password'), [
            'current_password' => $current, 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123',
        ]);
    }

    public function test_the_new_password_ends_the_other_sessions_and_the_remember_cookies(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => 'lembrar-123'])->save();
        $this->sessions($user, User::factory()->create());

        $this->change($user, 'password')->assertSessionHasNoErrors();

        // Da conta, sobra só a sessão de quem trocou (com o id novo); a de outra conta fica.
        $this->assertSame([session()->getId()], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
        $this->assertDatabaseHas('sessions', ['id' => 'de-outro']);
        $this->assertNotSame('lembrar-123', $user->refresh()->remember_token);
        $this->assertTrue(Hash::check('nova-senha-123', $user->password));
    }

    public function test_a_refused_change_ends_nothing(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => 'lembrar-123'])->save();
        $this->sessions($user, User::factory()->create());

        $this->change($user, 'errada')->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertSame(['celular-roubado', 'de-outro', 'notebook'], DB::table('sessions')->whereIn('id', ['notebook', 'celular-roubado', 'de-outro'])->orderBy('id')->pluck('id')->all());
        $this->assertSame('lembrar-123', $user->refresh()->remember_token);
    }
}
