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
use Throwable;

/**
 * O serviço da 2FA (E0a1), sem tela: o segredo cifrado, ligar só com um código
 * certo, o código de uma vez só, os códigos de recuperação só com hash e as
 * corridas que o Red Team achou (B1b a B4).
 */
class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_it_turns_on_only_with_a_right_code_and_keeps_the_secret_encrypted(): void
    {
        $user = User::factory()->create();
        $service = app(TwoFactor::class);
        $service->start($user);
        $secret = $this->secret($user);

        $raw = DB::table('user_two_factor')->where('user_id', $user->id)->value('secret');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, decrypt($raw, false));

        foreach (['000000', $this->otp($secret, -2)] as $wrong) {
            if (! in_array($wrong, [$this->otp($secret, -1), $this->otp($secret), $this->otp($secret, 1)], true)) {
                $this->assertThrows(fn () => $service->confirm($user, $wrong), ValidationException::class);
            }
        }

        $this->assertFalse(TwoFactor::enabled($user));
        $codes = $service->confirm($user, $this->otp($secret, -1));
        $this->assertTrue(TwoFactor::enabled($user));

        // Os códigos de recuperação: 10, mostrados uma vez (o retorno), e no banco só o hash.
        $this->assertCount(TwoFactor::RECOVERY_CODES, $codes);
        $stored = DB::table('user_two_factor')->where('user_id', $user->id)->value('recovery_codes');
        foreach ($codes as $code) {
            $this->assertStringNotContainsString(str_replace('-', '', $code), $stored);
        }
        $this->assertEqualsCanonicalizing(array_map(TwoFactor::hashRecoveryCode(...), $codes), json_decode($stored, true));
        $this->assertSame(TwoFactor::hashRecoveryCode($codes[0]), TwoFactor::hashRecoveryCode(strtolower(str_replace('-', ' ', $codes[0]))));

        // Códigos novos trocam todos; ligar de novo é recusado; cancelar não desliga; desligar apaga.
        $this->assertEmpty(array_intersect($service->regenerateRecoveryCodes($user), $codes));
        $this->assertThrows(fn () => $service->start($user), ActionRefused::class);
        $service->cancel($user);
        $this->assertTrue(TwoFactor::enabled($user));
        $service->disable($user);
        $this->assertDatabaseCount('user_two_factor', 0);
    }

    public function test_a_code_is_good_only_once(): void
    {
        $user = User::factory()->create();
        $service = app(TwoFactor::class);
        $service->start($user);
        $code = $this->otp($this->secret($user));
        $service->confirm($user, $code);

        $record = TwoFactor::current($user);
        $this->assertFalse($service->verify($record, $code), 'o mesmo código, de novo');
        $this->assertFalse($service->verify($record, $this->otp($record->secret, -1)), 'um código mais velho que o usado');
        $this->assertTrue($service->verify($record, $this->otp($record->secret, 1)));
        $this->assertFalse($service->verify(TwoFactor::current($user), $this->otp($record->secret, 1)), 'lido de novo do banco');
    }

    // B1b — a configuração abandonada vence: o segredo que ficou numa tela (ou num print) não liga a 2FA dias depois.
    public function test_rt_b1b_a_configuracao_pela_metade_vence(): void
    {
        $user = User::factory()->create();
        app(TwoFactor::class)->start($user);
        $secret = $this->secret($user);

        $this->travel(TwoFactor::PENDING_MINUTES + 1)->minutes();

        try {
            app(TwoFactor::class)->confirm($user, $this->otp($secret));
        } catch (ActionRefused|ValidationException) {
            // recusar é o desejado
        }

        $this->assertFalse(TwoFactor::enabled($user), 'Ligou com o segredo de uma configuração abandonada.');
    }

    // B2 — confirmar × "Ligar" de novo (outra aba): a confirmação liga com o segredo NOVO, que o app não tem.
    public function test_rt_b2_confirmar_nao_liga_com_um_segredo_trocado_no_meio(): void
    {
        $user = User::factory()->create();
        app(TwoFactor::class)->start($user);
        $first = $this->secret($user);

        $this->interleave(fn () => app(TwoFactor::class)->start($user));

        try {
            app(TwoFactor::class)->confirm($user, $this->otp($first));
        } catch (ActionRefused|ValidationException) {
            // recusar é o desejado
        }

        $row = UserTwoFactor::where('user_id', $user->id)->first();
        $this->assertFalse($row?->confirmed() && $row->secret !== $first, 'Ligou com um segredo que o app da pessoa não tem: ela fica trancada para fora.');
    }

    // B3 — "Ligar" × confirmar: o start() confere enabled() e depois reescreve a linha (updateOrCreate) sem condição.
    public function test_rt_b3_ligar_de_novo_nunca_reescreve_uma_2fa_ja_ligada(): void
    {
        $user = User::factory()->create();
        app(TwoFactor::class)->start($user);
        $first = $this->secret($user);

        // A confirmação da outra aba grava entre o enabled() e o save() do start.
        UserTwoFactor::updating(function () use ($user) {
            UserTwoFactor::flushEventListeners();
            DB::table('user_two_factor')->where('user_id', $user->id)->update(['confirmed_at' => now(), 'recovery_codes' => json_encode(['x'])]);
        });

        try {
            app(TwoFactor::class)->start($user);
        } catch (ActionRefused) {
            // recusar é o desejado
        } finally {
            UserTwoFactor::flushEventListeners();
        }

        $row = UserTwoFactor::where('user_id', $user->id)->first();
        $this->assertFalse($row->confirmed() && $row->secret !== $first, 'A 2FA ligada foi reescrita pelo "Ligar" da outra aba.');
    }

    // B4 — atribuição em massa: $guarded = ['id'] deixa ligar a 2FA (ou trocar o segredo) por um array.
    public function test_rt_b4_a_2fa_nao_liga_por_atribuicao_em_massa(): void
    {
        $user = User::factory()->create();

        try {
            UserTwoFactor::create(['user_id' => $user->id, 'secret' => 'AAAAAAAAAAAAAAAA', 'confirmed_at' => now()]);
        } catch (Throwable) {
            // recusar é o desejado
        }

        $this->assertFalse(TwoFactor::enabled($user), 'A 2FA ligou por atribuição em massa.');
    }
}
