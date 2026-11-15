<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\ReauthCodes;
use App\Services\Auth\Reauthentication;
use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Red Team da E0c (2FA na reautenticação e no /admin). Cada teste descreve o comportamento DESEJADO:
 * os `rt_h*`/`rt_i*` que falham no 20ee6e9 provam o achado (ver red-team/e0c/); os "evidência" passam.
 */
class TwoFactorReauthAttackTest extends TestCase
{
    use RefreshDatabase;

    private const ACTION = '/_teste/acao-sensivel-rt';

    private const IP = '203.0.113.7';

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

    /** Um Google2FA que, no meio da conferência do código, roda `$meanwhile` (as outras requisições). */
    private function interleave(Closure $meanwhile): void
    {
        $this->app->instance(Google2FA::class, new class($meanwhile) extends Google2FA
        {
            public function __construct(private ?Closure $meanwhile) {}

            public function verifyKeyNewer($secret, $key, $oldTimestamp, $window = null, $timestamp = null)
            {
                $result = parent::verifyKeyNewer($secret, $key, $oldTimestamp, $window, $timestamp);
                [$run, $this->meanwhile] = [$this->meanwhile, null];
                $run && $run();

                return $result;
            }
        });
        $this->app->forgetInstance(TwoFactor::class);
    }

    private function boxError(Closure $call): ?string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors()['reauth_two_factor_code'][0] ?? null;
        }

        return null;
    }

    // H1 — trocar a senha pede só a senha atual: com a sessão roubada e a senha, troca a senha, derruba as outras
    // sessões da dona e a deixa sem entrar (sem "esqueci a senha"), sem nunca digitar o código do app.
    public function test_rt_h1_trocar_a_senha_com_a_2fa_pede_o_codigo_do_app(): void
    {
        [$user] = $this->withTwoFactor();
        $this->actingAs($user);
        $new = ['current_password' => 'password', 'password' => 'trocada-123', 'password_confirmation' => 'trocada-123'];

        $this->from('/conta')->put('/conta/senha', $new)->assertRedirect('/conta');
        $this->assertTrue(Hash::check('password', $user->fresh()->password), 'a senha trocou sem o código do app');

        $this->from('/conta')->put('/conta/senha', $new + ['reauth_two_factor_code' => $this->otp($user)])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('trocada-123', $user->fresh()->password));
    }

    // H2 — a trava da reautenticação é a do login: quem tem só a senha (sem sessão nenhuma) erra 5 códigos no
    // /login/codigo a cada 15 min e a dona, logada, não confirma nada (nem trocar a senha para cortar o atacante).
    public function test_rt_h2_quem_so_tem_a_senha_nao_tranca_a_reautenticacao_da_dona(): void
    {
        [$user] = $this->withTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/login/codigo');
        foreach (range(1, 5) as $_) {
            $this->post('/login/codigo', ['two_factor_code' => '000000']);
        }

        $this->flushSession();
        $this->actingAs($user);
        $this->from('/conta')->post(self::ACTION, ['reauth_password' => 'password', 'reauth_two_factor_code' => $this->otp($user)])
            ->assertOk()->assertSee('feito');
    }

    // H3 — a mesma corrida da G1 (E0b) no TwoFactor::locked/failed: confere antes, conta depois.
    public function test_rt_h3_pedidos_ao_mesmo_tempo_nao_passam_do_limite_na_reautenticacao(): void
    {
        [$user] = $this->withTwoFactor();
        $session = $this->app['session']->driver();
        $session->start();
        Reauthentication::mark($user, 'password', $session);

        foreach (range(1, 4) as $i) {
            $this->boxError(fn () => app(Reauthentication::class)->confirmTwoFactor($user, $session, sprintf('00000%d', $i), self::IP));
        }

        $tried = 0;
        $this->interleave(function () use ($user, $session, &$tried) {
            foreach (range(5, 7) as $i) {
                $error = $this->boxError(fn () => app(Reauthentication::class)->confirmTwoFactor($user, $session, sprintf('00000%d', $i), self::IP));
                $tried += str_starts_with((string) $error, 'Muitas tentativas') ? 0 : 1;
            }
        });

        $this->boxError(fn () => app(Reauthentication::class)->confirmTwoFactor($user, $session, '999999', self::IP));

        $this->assertSame(0, $tried, 'com uma tentativa sobrando, só um pedido confere o código');
    }

    // I1 — TWO_FACTOR_ADMIN_REQUIRED só vale nas rotas /admin: o admin sem 2FA segue usando a área do cliente,
    // onde a conta de admin tem poder próprio (testes de segurança sem a aprovação), e troca e-mail e senha.
    public function test_rt_i1_o_admin_sem_2fa_so_chega_a_tela_de_configurar(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        config(['secmon.two_factor.admin_required' => true]);
        $this->actingAs($admin);

        $this->get('/conta')->assertRedirect(route('account.two-factor'));
        $this->get('/alertas')->assertRedirect(route('account.two-factor'));
        $this->get(route('account.two-factor'))->assertOk();

        // Quem não é admin não muda.
        $this->actingAs(User::factory()->create())->get('/conta')->assertOk();
    }

    // Evidência: nenhuma rota do /admin (fora o login) escapa da exigência.
    public function test_rt_evidencia_nenhuma_rota_do_admin_escapa_da_exigencia(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        config(['secmon.two_factor.admin_required' => true]);
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin') || $route->uri() === 'admin/login') {
                continue;
            }

            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $response = $this->actingAs($admin)->call($route->methods()[0], $uri);
            $this->assertContains($response->getStatusCode(), [302, 404, 405], "{$route->methods()[0]} {$uri}");

            if ($response->getStatusCode() === 302) {
                $this->assertSame(route('account.two-factor'), $response->headers->get('Location'), $uri);
                $checked++;
            }
        }

        $this->assertGreaterThan(40, $checked);
    }

    // Evidência (reauth:dominio): o código pedido para o aceite num domínio, mais o do app, vale só para ele.
    public function test_rt_evidencia_o_codigo_do_dominio_com_a_2fa_vale_so_para_o_dominio(): void
    {
        [$user] = $this->withTwoFactor();
        $session = $this->app['session']->driver();
        $session->start();

        Reauthentication::mark($user, 'code', $session, 'exemplo.com.br');
        $this->assertFalse(Reauthentication::recent($user, $session, 'exemplo.com.br'));
        app(Reauthentication::class)->confirmTwoFactor($user, $session, $this->otp($user), self::IP);

        $this->assertTrue(Reauthentication::recent($user, $session, 'exemplo.com.br'));
        $this->assertFalse(Reauthentication::recent($user, $session));
        $this->assertFalse(Reauthentication::recent($user, $session, 'outro.com.br'));
    }

    // Evidência (§4.13): nem o canal do Discord, nem o Slack, nem o webhook recebem o código; só a conversa privada.
    public function test_rt_evidencia_o_codigo_so_vai_a_conversa_privada(): void
    {
        $user = User::factory()->create();
        $base = ['user_id' => $user->id, 'status' => 'active', 'digest_days' => [1, 2, 3, 4, 5]];
        NotificationChannel::query()->create($base + ['type' => 'discord', 'method' => 'channel', 'discord_channel_id' => '111']);
        NotificationChannel::query()->create($base + ['type' => 'webhook', 'method' => 'webhook', 'webhook_url' => 'https://exemplo.com.br/secmon']);
        $private = NotificationChannel::query()->create($base + ['type' => 'telegram', 'method' => 'private', 'telegram_chat_id' => '123']);
        $session = $this->app['session']->driver();

        app(ReauthCodes::class)->rememberDestinations($user, $session);

        $this->assertSame([$private->id], app(ReauthCodes::class)->channels($user, $session)->pluck('id')->all());
    }
}
