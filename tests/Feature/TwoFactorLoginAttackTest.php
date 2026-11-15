<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\TwoFactor;
use App\Services\Auth\TwoFactorChallenge;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Red Team da E0b (2FA no login). Cada teste descreve o comportamento DESEJADO: os `rt_g*` que
 * falham no d67652e provam o achado (ver red-team/e0b/); os "evidência" passam e documentam o que resistiu.
 */
class TwoFactorLoginAttackTest extends TestCase
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

    // G1 — o limite confere "ainda não chegou a 5" e só conta o erro depois de conferir o código: N pedidos
    // ao mesmo tempo (a sessão não trava) passam todos pela conferência. Aqui, com 1 tentativa sobrando,
    // 3 pedidos que chegam enquanto o primeiro confere ainda tentam um código.
    public function test_rt_g1_pedidos_ao_mesmo_tempo_nao_passam_do_limite_de_codigos(): void
    {
        [$user] = $this->withTwoFactor();
        $session = $this->app['session']->driver();
        $session->start();
        app(TwoFactorChallenge::class)->begin($user, $session);
        $request = Request::create(self::CODE, 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->setLaravelSession($session);

        foreach (range(1, 4) as $_) {
            RateLimiter::hit('two-factor:'.$user->id, 900);
        }

        $tried = 0;
        $this->interleave(function () use ($request, &$tried) {
            foreach (range(1, 3) as $i) {
                try {
                    app(TwoFactorChallenge::class)->complete($request, sprintf('%06d', $i));
                } catch (ValidationException $e) {
                    $tried += str_starts_with($e->errors()['two_factor_code'][0], 'Muitas tentativas') ? 0 : 1;
                }
            }
        });

        try {
            app(TwoFactorChallenge::class)->complete($request, '999999');
        } catch (ValidationException) {
        }

        $this->assertSame(0, $tried, 'com uma tentativa sobrando, só um pedido confere o código');
    }

    // Evidência (DoS da conta): sem o passo da senha nesta sessão, o código nem é conferido e não conta na trava.
    public function test_rt_evidencia_sem_a_senha_ninguem_tranca_a_conta_da_vitima(): void
    {
        [$user] = $this->withTwoFactor();

        // 8 (o throttle da rota é 10 por minuto por IP): nenhum conta na trava da conta.
        foreach (range(1, 8) as $_) {
            $this->post(self::CODE, ['two_factor_code' => '000000'])->assertRedirect(route('login'));
        }

        $this->assertSame(0, RateLimiter::attempts('two-factor:'.$user->id));
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(self::CODE);
        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)]);
        $this->assertAuthenticatedAs($user);
    }

    // Evidência (fixação): o id da sessão troca na senha certa e de novo no código.
    public function test_rt_evidencia_o_id_da_sessao_troca_na_senha_e_no_codigo(): void
    {
        [$user] = $this->withTwoFactor();
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(self::CODE);
        $pending = session()->getId();
        $this->post(self::CODE, ['two_factor_code' => $this->otp($user)]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, $pending);
        $this->assertNotSame($pending, session()->getId());
    }

    // Evidência: "senha ok, falta o código" não abre nenhuma rota com login, nem a de ligar outra 2FA.
    public function test_rt_evidencia_o_estado_pendente_nao_abre_rotas_protegidas(): void
    {
        [$user] = $this->withTwoFactor();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertRedirect(self::CODE);

        foreach (['/conta', '/alertas', '/conta/duas-etapas'] as $page) {
            $this->get($page)->assertRedirect(route('login'));
        }

        $this->post('/conta/duas-etapas', ['reauth_password' => 'password'])->assertRedirect(route('login'));
        $this->delete('/conta/duas-etapas', ['reauth_password' => 'password'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull(TwoFactor::current($user)->confirmed_at);
    }

    // Evidência: o e-mail de "códigos novos" não leva os códigos.
    public function test_rt_evidencia_o_email_de_codigos_novos_nao_leva_os_codigos(): void
    {
        [$user] = $this->withTwoFactor();
        Queue::fake();

        $codes = app(TwoFactor::class)->regenerateRecoveryCodes($user);

        Queue::assertPushed(SendEmail::class, function (SendEmail $job) use ($codes) {
            $html = $job->mail->render();

            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, $html);
                $this->assertStringNotContainsString(str_replace('-', '', $code), $html);
            }

            return true;
        });
    }
}
