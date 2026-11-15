<?php

namespace Tests\Feature\Account;

use App\Exceptions\ActionRefused;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Red Team da E0a. Cada teste descreve o comportamento DESEJADO: os que falham no 03f855b provam
 * o achado (ver red-team/e0a/); os "evidência" passam e documentam o que resistiu.
 */
class TwoFactorAttackTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/conta/duas-etapas';

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.two_factor.enabled' => true]);
        $this->travelTo(now()->setTime(12, 0, 15));
    }

    private function otp(string $secret, int $steps = 0): string
    {
        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + $steps);
    }

    private function secret(User $user): string
    {
        return UserTwoFactor::where('user_id', $user->id)->firstOrFail()->secret;
    }

    /** Um Google2FA que, no meio da conferência do código, roda `$meanwhile` (a outra requisição). */
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

    // B1 — o segredo pela metade aparece para uma sessão sem reautenticação (outro aparelho logado, ou a mesma depois de 10 min).
    public function test_rt_b1_o_qr_pela_metade_nao_aparece_sem_reautenticacao_recente(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(self::PAGE, ['reauth_password' => 'password'])->assertRedirect(self::PAGE);
        $secret = $this->secret($user);

        // Outra sessão da mesma conta (cookie roubado de outro aparelho): sem reautenticar.
        $this->flushSession();
        $this->actingAs($user)->get(self::PAGE)->assertOk()->assertDontSee(trim(chunk_split($secret, 4, ' ')))->assertDontSee($secret);

        // A mesma sessão, 11 minutos depois (a reautenticação venceu).
        $this->travel(11)->minutes();
        $this->get(self::PAGE)->assertOk()->assertDontSee($secret);
    }

    // B5 — a página com o segredo ou os códigos de recuperação não fica no cache do navegador (voltar, disco).
    public function test_rt_b5_a_pagina_nao_fica_no_cache_do_navegador(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(self::PAGE, ['reauth_password' => 'password']);

        $this->assertStringContainsString('no-store', (string) $this->get(self::PAGE)->headers->get('Cache-Control'));

        $this->post(self::PAGE.'/confirmar', ['two_factor_code' => $this->otp($this->secret($user))]);
        $this->assertStringContainsString('no-store', (string) $this->get(self::PAGE)->headers->get('Cache-Control'));
    }

    // Evidência — confirmar × cancelar (DELETE …/configuracao, sem senha), nas duas ordens: nunca sobra 2FA meia-ligada.
    public function test_evidencia_confirmar_e_cancelar_juntos(): void
    {
        $user = User::factory()->create();
        app(TwoFactor::class)->start($user);
        $code = $this->otp($this->secret($user));

        // O cancelar chega no meio da conferência: a gravação condicional do passo não acha a linha.
        $this->interleave(fn () => app(TwoFactor::class)->cancel($user));

        try {
            app(TwoFactor::class)->confirm($user, $code);
            $this->fail('Ligou uma configuração cancelada.');
        } catch (ActionRefused|ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('user_two_factor', 0);

        // O cancelar depois de ligar não desliga, nem por uma sessão sem senha.
        $this->app->forgetInstance(Google2FA::class);
        $this->app->forgetInstance(TwoFactor::class);
        app(TwoFactor::class)->start($user);
        app(TwoFactor::class)->confirm($user, $this->otp($this->secret($user)));
        $this->actingAs($user)->delete(self::PAGE.'/configuracao')->assertRedirect(self::PAGE);
        $this->delete(self::PAGE)->assertRedirect();
        $this->assertTrue(TwoFactor::enabled($user));
    }

    // Evidência — a chave desligada: 404 antes do limite e da senha (11 tentativas, nenhuma 429 nem pedido de senha).
    public function test_evidencia_chave_desligada_404_antes_do_limite(): void
    {
        config(['secmon.two_factor.enabled' => false]);
        $this->actingAs(User::factory()->create());

        foreach (range(1, 11) as $i) {
            $this->post(self::PAGE, ['reauth_password' => 'errada'])->assertNotFound();
            $this->delete(self::PAGE.'/configuracao')->assertNotFound();
        }

        config(['secmon.two_factor.enabled' => true]);
        $this->from(self::PAGE)->post(self::PAGE, ['reauth_password' => 'password'])->assertRedirect(self::PAGE);
    }

    // Evidência — os códigos de recuperação: só cifrados na sessão, só HMAC no banco, nunca de novo na tela; o segredo some depois de ligar.
    public function test_evidencia_codigos_e_segredo_nao_vazam(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(self::PAGE, ['reauth_password' => 'password']);
        $secret = $this->secret($user);

        $this->post(self::PAGE.'/confirmar', ['two_factor_code' => $this->otp($secret)])->assertRedirect(self::PAGE);
        $flash = session('recovery_codes');
        $codes = decrypt($flash);
        $this->assertCount(10, $codes);
        $this->assertStringNotContainsString($codes[0], $flash);
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}$/', $codes[0]);

        $first = $this->get(self::PAGE)->assertSee($codes[0]);
        $first->assertDontSee($secret);
        $this->get(self::PAGE)->assertDontSee($codes[0])->assertDontSee($secret);

        $row = UserTwoFactor::where('user_id', $user->id)->first();
        $stored = DB::table('user_two_factor')->where('user_id', $user->id)->value('recovery_codes');
        $this->assertStringNotContainsString(str_replace('-', '', $codes[0]), $stored);
        $this->assertSame(hash_hmac('sha256', str_replace('-', '', $codes[0]), config('app.key')), $row->recovery_codes[0]);
        $this->assertStringNotContainsString($secret, $row->toJson().$user->fresh()->toJson());
    }
}
