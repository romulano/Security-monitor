<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * A 2FA no login (E0b): a senha certa (ou a volta do GitHub) de quem ligou a
 * 2FA não entra até o código do app ou um código de recuperação, e os desvios
 * (pular a tela, outra sessão, outra conta, força bruta) não passam.
 */
class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '/login/codigo';

    protected function setUp(): void
    {
        parent::setUp();

        config(['secmon.two_factor.enabled' => true]);
        $this->travelTo(now()->setTime(12, 0, 15));
    }

    private function otp(User $user, int $steps = 0): string
    {
        $secret = UserTwoFactor::where('user_id', $user->id)->firstOrFail()->secret;

        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + $steps);
    }

    /** Conta com a 2FA ligada; devolve os códigos de recuperação. O relógio anda 1 min (o código de ligar já foi usado). */
    private function withTwoFactor(array $attributes = []): array
    {
        $user = User::factory()->create($attributes);
        app(TwoFactor::class)->start($user);
        $codes = app(TwoFactor::class)->confirm($user, $this->otp($user));
        $this->travel(1)->minutes();

        return [$user->fresh(), $codes];
    }

    private function password(User $user, array $extra = [])
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password'] + $extra);
    }

    public function test_an_account_without_two_factor_logs_in_as_before(): void
    {
        $user = User::factory()->create();

        $this->password($user)->assertRedirect(route('account.show'));
        $this->assertAuthenticatedAs($user);
        $this->get(self::CODE)->assertRedirect();
    }

    public function test_the_password_alone_does_not_enter_and_the_code_does(): void
    {
        [$user] = $this->withTwoFactor();
        $this->get('/alertas')->assertRedirect(route('login'));

        $this->password($user)->assertRedirect(self::CODE);
        $this->assertGuest();

        // Ir direto à área logada não passa.
        $this->get('/conta')->assertRedirect(route('login'));
        $this->get('/alertas')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->get(self::CODE)->assertOk()->assertSee('Verificação em duas etapas');
        $this->from(self::CODE)->post(self::CODE, ['two_factor_code' => '000000'])->assertSessionHasErrors('two_factor_code');
        $this->assertGuest();

        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)])->assertRedirect('/alertas');
        $this->assertAuthenticatedAs($user);
        $this->get(self::CODE)->assertRedirect();
    }

    public function test_continue_connected_is_only_set_after_the_code(): void
    {
        [$user] = $this->withTwoFactor();
        $recaller = Auth::guard()->getRecallerName();

        $this->password($user, ['remember' => '1'])->assertCookieMissing($recaller);
        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)])->assertCookie($recaller);
    }

    public function test_turning_two_factor_on_drops_the_old_sessions_and_continue_connected(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['remember_token' => 'antigo']);
        DB::table('sessions')->insert(array_map(fn ($id) => ['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()], ['outra', 'esta']));

        app(TwoFactor::class)->start($user);
        app(TwoFactor::class)->confirm($user, $this->otp($user), keepSession: 'esta');

        $this->assertNotSame('antigo', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'outra']);
        $this->assertDatabaseHas('sessions', ['id' => 'esta']);
    }

    public function test_the_account_gets_an_email_when_two_factor_changes(): void
    {
        [$user] = $this->withTwoFactor();
        app(TwoFactor::class)->regenerateRecoveryCodes($user);
        app(TwoFactor::class)->disable($user);
        app(TwoFactor::class)->disable($user);

        $this->assertSame([
            'Security Monitor: a verificação em duas etapas foi ligada',
            'Security Monitor: a verificação em duas etapas ganhou códigos de recuperação novos',
            'Security Monitor: a verificação em duas etapas foi desligada',
        ], DB::table('email_messages')->where(['user_id' => $user->id, 'kind' => 'seguranca'])->orderBy('id')->pluck('subject')->all());
    }

    public function test_the_wait_for_the_code_lasts_five_minutes(): void
    {
        [$user] = $this->withTwoFactor();
        $this->password($user)->assertRedirect(self::CODE);

        $this->travel(5)->minutes();
        $this->get(self::CODE)->assertRedirect(route('login'));
        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_another_session_does_not_have_the_password_step(): void
    {
        [$user] = $this->withTwoFactor();
        $this->password($user)->assertRedirect(self::CODE);

        $this->flushSession();
        $this->get(self::CODE)->assertRedirect(route('login'));
        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_password_of_another_account_replaces_the_wait(): void
    {
        [$ana] = $this->withTwoFactor();
        [$bruno] = $this->withTwoFactor();

        $this->password($ana)->assertRedirect(self::CODE);
        $this->password($bruno)->assertRedirect(self::CODE);

        // O código da Ana não entra em conta nenhuma; o do Bruno entra na dele.
        $this->post(self::CODE, ['two_factor_code' => $this->otp($ana)])->assertSessionHasErrors('two_factor_code');
        $this->assertGuest();
        $this->post(self::CODE, ['two_factor_code' => $this->otp($bruno)]);
        $this->assertAuthenticatedAs($bruno);
    }

    public function test_github_asks_for_the_code_and_links_only_after_it(): void
    {
        config(['services.github.client_id' => 'Iv1.abc', 'services.github.client_secret' => 'segredo']);
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
            'api.github.com/user/emails' => Http::response([['email' => 'maria@example.com', 'primary' => true, 'verified' => true]]),
            'api.github.com/user' => Http::response(['id' => 4242, 'login' => 'maria-dev', 'name' => 'Maria']),
            'api.github.com/applications/*' => Http::response('', 204),
        ]);
        [$user] = $this->withTwoFactor(['email' => 'maria@example.com', 'email_verified_at' => null]);

        parse_str(parse_url($this->get(route('login.github'))->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get(route('login.github.callback', ['code' => 'c', 'state' => $query['state']]))->assertRedirect(self::CODE);

        $this->assertGuest();
        $this->assertNull($user->fresh()->github_id, 'a ligação ao GitHub espera o código');
        $this->assertNull($user->fresh()->email_verified_at);

        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)]);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('4242', (string) $user->fresh()->github_id);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_five_wrong_codes_lock_the_account_even_for_the_right_one(): void
    {
        [$user] = $this->withTwoFactor();
        $this->password($user);

        foreach (range(1, 5) as $_) {
            $this->post(self::CODE, ['two_factor_code' => '000000'])->assertSessionHasErrors('two_factor_code');
            $this->assertStringStartsWith('O código não confere.', session('errors')->first('two_factor_code'));
        }

        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)])->assertSessionHasErrors('two_factor_code');
        $this->assertStringStartsWith('Muitas tentativas', session('errors')->first('two_factor_code'));
        $this->assertGuest();

        // A conta é avisada por e-mail, uma vez só por trava.
        $this->post(self::CODE, ['two_factor_code' => '000000']);
        $this->assertSame(1, DB::table('email_messages')->where(['user_id' => $user->id, 'subject' => 'Security Monitor: a verificação em duas etapas travou depois de códigos errados'])->count());

        // Passados os 15 minutos, a senha de novo e o código certo entram.
        $this->travel(15)->minutes();
        $this->password($user)->assertRedirect(self::CODE);
        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_too_many_wrong_codes_from_one_ip_lock_every_account(): void
    {
        [$user] = $this->withTwoFactor();
        foreach (range(1, 20) as $_) {
            RateLimiter::hit('two-factor-ip:127.0.0.1', 900);
        }

        $this->password($user);
        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)])->assertSessionHasErrors('two_factor_code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_enters_once(): void
    {
        [$user, $codes] = $this->withTwoFactor();

        $this->password($user);
        $this->post(self::CODE, ['two_factor_code' => strtolower(str_replace('-', ' ', $codes[0]))])
            ->assertSessionHas('status', 'Você entrou com um código de recuperação, que agora não vale mais. Restam 9.');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(9, TwoFactor::current($user)->recovery_codes);

        $this->post('/logout');
        $this->password($user);
        $this->post(self::CODE, ['two_factor_code' => $codes[0]])
            ->assertSessionHasErrors(['two_factor_code' => 'Este código de recuperação não confere ou já foi usado. Confira as letras e o hífen.']);
        $this->assertGuest();
        $this->get(self::CODE.'?recuperacao=1')->assertOk()->assertSee('Código de recuperação')->assertSee('Usar o código do app');
    }

    public function test_the_secret_and_the_recovery_codes_survive_a_new_app_key(): void
    {
        [$user, $codes] = $this->withTwoFactor();

        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->password($user);
        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)]);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->password($user);
        $this->post(self::CODE, ['two_factor_code' => $codes[1]]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_admin_without_two_factor_and_the_switch_off_do_not_ask_for_the_code(): void
    {
        // O admin com a 2FA ligada passa pelo código (TwoFactorReauthTest); sem ela, nada muda.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->post(route('admin.login'), ['email' => $admin->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($admin);
        $this->post('/logout');

        config(['secmon.two_factor.enabled' => false]);
        [$user] = $this->withTwoFactor();
        $this->password($user)->assertRedirect(route('account.show'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        $this->get(self::CODE)->assertNotFound();
    }
}
