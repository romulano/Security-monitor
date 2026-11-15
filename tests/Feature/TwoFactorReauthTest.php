<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\ReauthCodes;
use App\Services\Auth\Reauthentication;
use App\Services\Auth\TwoFactor;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * A 2FA nas outras portas (E0c): a reautenticação (senha, GitHub ou código no
 * app) pede também o código do app de quem tem a 2FA, o /admin/login pede o
 * código do admin que a ligou, e a chave TWO_FACTOR_ADMIN_REQUIRED.
 */
class TwoFactorReauthTest extends TestCase
{
    use RefreshDatabase;

    private const ACTION = '/_teste/acao-sensivel';

    private const BOX = '/conta/reautenticar/duas-etapas';

    protected function setUp(): void
    {
        parent::setUp();

        config(['secmon.two_factor.enabled' => true]);
        $this->travelTo(now()->setTime(12, 0, 15));
        Route::middleware(['web', 'auth', 'reauth'])->post(self::ACTION, fn () => 'feito');
    }

    private function otp(User $user, int $steps = 0): string
    {
        $secret = UserTwoFactor::where('user_id', $user->id)->firstOrFail()->secret;

        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + $steps);
    }

    /** @return array{0: User, 1: list<string>} */
    private function withTwoFactor(array $attributes = []): array
    {
        $user = User::factory()->create($attributes);
        app(TwoFactor::class)->start($user);
        $codes = app(TwoFactor::class)->confirm($user, $this->otp($user));
        $this->travel(1)->minutes();

        return [$user->fresh(), $codes];
    }

    private function act(array $data = [])
    {
        return $this->from('/conta')->post(self::ACTION, $data);
    }

    public function test_without_two_factor_the_password_alone_still_confirms(): void
    {
        $this->actingAs(User::factory()->create());

        $this->act(['reauth_password' => 'password'])->assertOk()->assertSee('feito');
    }

    public function test_with_two_factor_the_password_alone_does_not_confirm(): void
    {
        [$user] = $this->withTwoFactor();
        $this->actingAs($user);

        $this->act(['reauth_password' => 'password'])
            ->assertRedirect('/conta')
            ->assertSessionHasErrorsIn('reauth_next', ['reauth_two_factor_code' => 'Agora digite o código do app autenticador.']);
        $this->assertFalse(Reauthentication::recent($user, session()->driver()));
        $this->assertTrue(Reauthentication::needsTwoFactor($user, session()->driver()));

        // A caixa "Falta o código do app": com ele, a ação passa sem a senha nos 10 minutos.
        $this->get('/conta')->assertSee('Agora falta o código do app autenticador')->assertSee('name="reauth_two_factor_code"', false);
        $this->from('/conta')->post(self::BOX, ['reauth_two_factor_code' => $this->otp($user)])->assertRedirect('/conta');
        $this->act()->assertOk()->assertSee('feito');
    }

    public function test_the_password_and_the_code_in_the_same_form_confirm(): void
    {
        [$user] = $this->withTwoFactor();
        $this->actingAs($user);

        $this->act(['reauth_password' => 'password', 'reauth_two_factor_code' => '000000'])
            ->assertSessionHasErrorsIn('reauth', 'reauth_two_factor_code');
        $this->act(['reauth_password' => 'password', 'reauth_two_factor_code' => $this->otp($user)])->assertOk();
    }

    public function test_github_and_the_code_in_an_app_are_only_the_first_step(): void
    {
        [$user] = $this->withTwoFactor();

        foreach (['github', 'code'] as $method) {
            $this->flushSession();
            $this->actingAs($user)->get('/conta');
            Reauthentication::mark($user, $method, session()->driver());
            $this->assertFalse(Reauthentication::recent($user, session()->driver()), "{$method} sozinho");
            $this->act()->assertSessionHasErrorsIn('reauth_next', 'reauth_two_factor_code');

            $this->from('/conta')->post(self::BOX, ['reauth_two_factor_code' => $this->otp($user)]);
            $this->assertSame($method, Reauthentication::method($user, session()->driver()));
            $this->travel(1)->minutes();
        }
    }

    public function test_the_box_needs_a_first_step_and_a_recovery_code_also_works_once(): void
    {
        [$user, $codes] = $this->withTwoFactor();
        $this->actingAs($user);

        $this->from('/conta')->post(self::BOX, ['reauth_two_factor_code' => $this->otp($user)])
            ->assertSessionHasErrorsIn('reauth', ['reauth_two_factor_code' => 'Primeiro confirme que é você com a senha, o GitHub ou um código.']);

        $this->act(['reauth_password' => 'password', 'reauth_two_factor_code' => $codes[0]])->assertOk();
        $this->assertCount(TwoFactor::RECOVERY_CODES - 1, TwoFactor::current($user)->recovery_codes);
    }

    public function test_wrong_codes_lock_this_session_but_not_the_login(): void
    {
        [$user] = $this->withTwoFactor();
        $this->actingAs($user);

        foreach (range(1, 5) as $_) {
            $this->act(['reauth_password' => 'password', 'reauth_two_factor_code' => '000000']);
        }

        $this->act(['reauth_two_factor_code' => $this->otp($user)])->assertSessionHasErrorsIn('reauth', 'reauth_two_factor_code');
        $this->assertStringStartsWith('Muitas tentativas', session('errors')->getBag('reauth')->first('reauth_two_factor_code'));
        // A trava é da sessão (H2 do Red Team): o login da conta segue aberto.
        $this->assertNull(TwoFactor::locked($user, null));
    }

    public function test_changing_the_email_with_two_factor_needs_the_code(): void
    {
        [$user] = $this->withTwoFactor();
        $this->actingAs($user);

        $this->from('/conta')->put('/conta', ['name' => $user->name, 'email' => 'novo@example.com', 'current_password' => 'password'])
            ->assertSessionHasErrorsIn('reauth_next', 'reauth_two_factor_code');
        $this->assertNotSame('novo@example.com', $user->fresh()->email);

        $this->from('/conta')->post(self::BOX, ['reauth_two_factor_code' => $this->otp($user)]);
        $this->from('/conta')->put('/conta', ['name' => $user->name, 'email' => 'novo@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('novo@example.com', $user->fresh()->email);
    }

    public function test_no_code_goes_to_a_webhook_or_a_group_channel(): void
    {
        $user = User::factory()->create();
        $base = ['user_id' => $user->id, 'status' => 'active', 'digest_days' => [1, 2, 3, 4, 5]];
        NotificationChannel::query()->create($base + ['type' => 'webhook', 'method' => 'webhook', 'webhook_url' => 'https://exemplo.com.br/secmon']);
        NotificationChannel::query()->create($base + ['type' => 'telegram', 'method' => 'group', 'telegram_chat_id' => '-100123']);
        $session = $this->app['session']->driver();

        app(ReauthCodes::class)->rememberDestinations($user, $session);

        $this->assertTrue(app(ReauthCodes::class)->channels($user, $session)->isEmpty());
    }

    public function test_the_admin_login_asks_for_the_code_of_an_admin_with_two_factor(): void
    {
        [$admin] = $this->withTwoFactor(['is_admin' => true]);

        $this->post(route('admin.login'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/login/codigo');
        $this->assertGuest();
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/login/codigo')->assertOk()->assertSee(route('admin.login'));

        $this->post('/login/codigo', ['two_factor_code' => $this->otp($admin)])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_an_inactive_company_account_never_reaches_the_two_factor_setup(): void
    {
        config(['secmon.enterprise.enabled' => true, 'secmon.two_factor.admin_required' => true]);
        app(Companies::class)->create('Acme', 'Ana', 'ana@acme.com');
        $ana = User::query()->where('email', 'ana@acme.com')->sole();

        $this->actingAs($ana)->get(route('account.two-factor'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_admin_requirement_switch_sends_an_admin_without_two_factor_to_the_setup(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin')->assertOk();

        config(['secmon.two_factor.admin_required' => true]);
        $this->get('/admin')->assertRedirect(route('account.two-factor'));
        $this->get(route('account.two-factor'))->assertOk();

        // Ligada pela tela, ela oferece voltar direto ao admin (o url.intended guardado no desvio).
        $this->post(route('account.two-factor.start'), ['reauth_password' => 'password']);
        $this->post(route('account.two-factor.confirm'), ['two_factor_code' => $this->otp($admin)]);
        $this->get(route('account.two-factor'))->assertSee('voltar ao admin')->assertSee(url('/admin'));
        $this->get('/admin')->assertOk();
    }
}
