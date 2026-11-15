<?php

namespace Tests\Feature\Company;

use App\Exceptions\ActionRefused;
use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/** Um e-mail, uma conta, sem diferença de caixa (Red Team da E1a). */
class EmailCaseTest extends TestCase
{
    use RefreshDatabase;

    private function companies(): Companies
    {
        return app(Companies::class);
    }

    private function admin(string $email = 'ana@acme.com'): User
    {
        return User::query()->where('email', $email)->sole();
    }

    // A1 — e-mail com outra caixa cria conta pessoal ao lado da de empresa.
    public function test_rt_a1_cadastro_com_outra_caixa_nao_duplica_a_conta_da_empresa(): void
    {
        $this->companies()->create('Acme', 'Ana', 'ana@acme.com');

        $this->post(route('register'), ['name' => 'Ana', 'email' => 'ANA@Acme.com', 'password' => 'minha-senha-123', 'password_confirmation' => 'minha-senha-123']);

        $this->assertSame(1, User::query()->whereRaw('lower(email) = ?', ['ana@acme.com'])->count(), 'Duas contas para o mesmo e-mail.');
    }

    // A1b — a mesma duplicação pela corrida, mesmo com o lower() do exists(): só um índice único em lower(email) fecha.
    public function test_rt_a1b_corrida_com_outra_caixa_nao_duplica(): void
    {
        User::creating(function (User $user) {
            if ($user->email === 'ana@acme.com' && ! DB::table('users')->where('email', 'Ana@Acme.com')->exists()) {
                DB::table('users')->insert(['name' => 'Cadastro', 'email' => 'Ana@Acme.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        try {
            $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        } catch (Throwable) {
            // recusado: é o desejado
        } finally {
            User::flushEventListeners();
        }

        // 0 também serve: o insert do "outro pedido" volta junto no savepoint do teste.
        $this->assertLessThanOrEqual(1, User::query()->whereRaw('lower(email) = ?', ['ana@acme.com'])->count(), 'Duas contas para o mesmo e-mail.');
    }

    // A troca de e-mail na conta também não aceita o e-mail de outra conta com outra caixa; a própria caixa pode mudar.
    public function test_trocar_o_email_para_o_de_outra_conta_com_outra_caixa_e_recusado(): void
    {
        $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $bruno = User::factory()->create(['email' => 'bruno@loja.com', 'password' => 'senha-do-bruno-1']);

        $this->actingAs($bruno)->from(route('account.show'))
            ->put(route('account.update'), ['name' => $bruno->name, 'email' => 'ANA@acme.com', 'current_password' => 'senha-do-bruno-1'])
            ->assertSessionHasErrors('email', null, 'profile');
        $this->assertSame('bruno@loja.com', $bruno->fresh()->email);

        $this->actingAs($bruno)->from(route('account.show'))
            ->put(route('account.update'), ['name' => $bruno->name, 'email' => 'Bruno@Loja.com', 'current_password' => 'senha-do-bruno-1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Bruno@Loja.com', $bruno->fresh()->email);
    }

    // O banco também barra, fora de qualquer tela.
    public function test_o_indice_barra_o_mesmo_email_com_outra_caixa(): void
    {
        User::factory()->create(['email' => 'ana@acme.com']);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['email' => 'Ana@ACME.com']);
    }

    /** Outro cadastro grava o mesmo e-mail, com outra caixa, entre a regra e o insert. */
    private function raceWith(string $email, string $other): void
    {
        User::creating(function (User $user) use ($email, $other) {
            if (mb_strtolower($user->email) === $email && ! DB::table('users')->where('email', $other)->exists()) {
                DB::table('users')->insert(['name' => 'Outro cadastro', 'email' => $other, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function test_a_corrida_no_cadastro_vira_erro_de_validacao_e_nao_500(): void
    {
        $this->raceWith('ana@acme.com', 'ANA@acme.com');

        $this->from(route('register'))
            ->post(route('register'), ['name' => 'Ana', 'email' => 'ana@acme.com', 'password' => 'minha-senha-123', 'password_confirmation' => 'minha-senha-123'])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['email' => 'Já existe uma conta com este e-mail.']);

        $this->assertGuest();
        // 0 também serve: o insert do "outro cadastro" volta junto no savepoint do teste.
        $this->assertLessThanOrEqual(1, User::query()->whereRaw('lower(email) = ?', ['ana@acme.com'])->count());
    }

    public function test_a_corrida_no_cadastro_pelo_github_vira_a_recusa_de_sempre(): void
    {
        config(['services.github.client_id' => 'Iv1.abc', 'services.github.client_secret' => 'segredo']);
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
            'api.github.com/user/emails' => Http::response([['email' => 'maria@example.com', 'primary' => true, 'verified' => true]]),
            'api.github.com/user' => Http::response(['id' => 4242, 'login' => 'maria-dev', 'name' => 'Maria']),
            'api.github.com/applications/*' => Http::response('', 204),
        ]);
        $this->raceWith('maria@example.com', 'Maria@Example.com');

        parse_str(parse_url($this->post(route('register.github'))->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get(route('login.github.callback', ['code' => 'c', 'state' => $query['state']]))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'Já existe uma conta'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['github_id' => '4242']);
    }

    public function test_o_login_nao_diferencia_caixa_no_email(): void
    {
        $ana = User::factory()->create(['email' => 'Ana@Loja.com', 'password' => 'senha-da-ana-123']);

        $this->post(route('login'), ['email' => 'ana@LOJA.com', 'password' => 'senha-errada-123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login'), ['email' => 'ana@LOJA.com', 'password' => 'senha-da-ana-123'])->assertRedirect(route('account.show'));
        $this->assertAuthenticatedAs($ana);

        // A porta do admin continua só para admin, em qualquer caixa.
        auth()->logout();
        $this->post(route('admin.login'), ['email' => 'ANA@loja.com', 'password' => 'senha-da-ana-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // Evidência — e-mail ocupado por cadastro NÃO confirmado barra a criação da empresa (e, na E1c, o convite).
    public function test_evidencia_cadastro_nao_confirmado_ocupa_o_email_da_admin(): void
    {
        User::factory()->unverified()->create(['email' => 'ana@acme.com']);

        $this->expectException(ActionRefused::class);
        $this->companies()->create('Acme', 'Ana', 'Ana@Acme.com');
    }
}
