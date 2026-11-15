<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * A verificação em duas etapas na conta (E0a): ligar com o app, os códigos de
 * recuperação e desligar. Ainda não entra no login, e sem TWO_FACTOR_ENABLED
 * nada disso existe.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/conta/duas-etapas';

    protected function setUp(): void
    {
        parent::setUp();

        config(['secmon.two_factor.enabled' => true]);
        $this->travelTo(now()->setTime(12, 0, 15));
    }

    /** O código do app no passo atual (ou `$steps` passos antes ou depois). */
    private function otp(string $secret, int $steps = 0): string
    {
        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + $steps);
    }

    private function secret(User $user): string
    {
        return UserTwoFactor::where('user_id', $user->id)->firstOrFail()->secret;
    }

    /** Conta com a 2FA ligada pelo serviço, sem passar pela tela; devolve os códigos de recuperação. */
    private function enable(User $user): array
    {
        $service = app(TwoFactor::class);
        $service->start($user);
        $codes = $service->confirm($user, $this->otp($this->secret($user)));
        $this->travel(30)->seconds();

        return $codes;
    }

    public function test_without_the_flag_nothing_exists_and_the_account_page_does_not_change(): void
    {
        config(['secmon.two_factor.enabled' => false]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(self::PAGE)->assertNotFound();
        // 404 antes da senha: sem a senha no formulário, não volta pedindo a senha.
        $this->post(self::PAGE)->assertNotFound();
        $this->post(self::PAGE.'/confirmar', ['two_factor_code' => '123456'])->assertNotFound();
        $this->post(self::PAGE.'/codigos')->assertNotFound();
        $this->delete(self::PAGE, ['reauth_password' => 'password'])->assertNotFound();

        $this->get('/conta')->assertOk()->assertDontSee('Verificação em duas etapas');
        $this->assertDatabaseCount('user_two_factor', 0);
    }

    public function test_an_account_without_two_factor_sees_it_off_and_starting_asks_who_you_are(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/conta')->assertOk()->assertSee('Verificação em duas etapas')->assertSee('Configurar');
        $this->get(self::PAGE)->assertOk()->assertSee('Desligada')->assertSee('Ligar a verificação em duas etapas');

        // Ligar pede a reautenticação.
        $this->from(self::PAGE)->post(self::PAGE)->assertRedirect(self::PAGE)->assertSessionHasErrorsIn('reauth', 'reauth_password');

        $this->assertFalse(TwoFactor::enabled($user));
        $this->assertDatabaseCount('user_two_factor', 0);
    }

    public function test_it_turns_on_only_with_the_right_code_and_the_secret_is_encrypted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(self::PAGE, ['reauth_password' => 'password'])->assertRedirect(self::PAGE);
        $secret = $this->secret($user);

        // No banco, o segredo está cifrado; a tela mostra o QR e o segredo para digitar.
        $raw = DB::table('user_two_factor')->where('user_id', $user->id)->value('secret');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, decrypt($raw, false));
        $this->get(self::PAGE)->assertOk()->assertSee('QR para cadastrar o Security Monitor')->assertSee(trim(chunk_split($secret, 4, ' ')));
        $this->assertFalse(TwoFactor::enabled($user));

        // Errado, e de dois passos atrás (fora da janela): continua desligada.
        foreach (['000000', $this->otp($secret, -2), 'abcdef'] as $wrong) {
            if ($wrong === $this->otp($secret) || $wrong === $this->otp($secret, -1) || $wrong === $this->otp($secret, 1)) {
                continue;
            }

            $this->from(self::PAGE)->post(self::PAGE.'/confirmar', ['two_factor_code' => $wrong])
                ->assertRedirect(self::PAGE)
                ->assertSessionHasErrorsIn('two_factor', 'two_factor_code');
        }

        $this->assertFalse(TwoFactor::enabled($user));

        // O do passo anterior ainda vale (relógio do celular atrasado).
        $this->post(self::PAGE.'/confirmar', ['two_factor_code' => $this->otp($secret, -1)])
            ->assertRedirect(self::PAGE)
            ->assertSessionHas('status', 'Verificação em duas etapas ligada.');

        $this->assertTrue(TwoFactor::enabled($user));
        // Ligada, o segredo não aparece mais.
        $this->get(self::PAGE)->assertOk()->assertSee('Ligada')->assertDontSee(trim(chunk_split($secret, 4, ' ')))->assertDontSee($secret)->assertDontSee('QR para cadastrar o Security Monitor');
    }

    public function test_recovery_codes_are_shown_once_and_stored_only_as_hashes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(self::PAGE, ['reauth_password' => 'password']);

        $this->post(self::PAGE.'/confirmar', ['two_factor_code' => $this->otp($this->secret($user))]);
        $page = $this->get(self::PAGE)->assertOk()->assertSee('Guarde os seus códigos de recuperação');
        preg_match_all('/\b[A-Z2-9]{5}-[A-Z2-9]{5}\b/', $page->getContent(), $found);
        $codes = array_values(array_unique($found[0]));
        $this->assertCount(TwoFactor::RECOVERY_CODES, $codes);

        // Na próxima vez, não aparecem mais.
        $this->get(self::PAGE)->assertOk()->assertDontSee('Guarde os seus códigos de recuperação')->assertDontSee($codes[0]);

        $row = DB::table('user_two_factor')->where('user_id', $user->id)->first();
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $row->recovery_codes);
            $this->assertStringNotContainsString(str_replace('-', '', $code), $row->recovery_codes);
        }
        $this->assertEqualsCanonicalizing(array_map(TwoFactor::hashRecoveryCode(...), $codes), json_decode($row->recovery_codes, true));
        $this->assertSame(TwoFactor::hashRecoveryCode($codes[0]), TwoFactor::hashRecoveryCode(strtolower(str_replace('-', ' ', $codes[0]))));
    }

    public function test_new_recovery_codes_ask_who_you_are_and_replace_the_old_ones(): void
    {
        $user = User::factory()->create();
        $old = $this->enable($user);

        $this->actingAs($user)->from(self::PAGE)->post(self::PAGE.'/codigos')->assertSessionHasErrorsIn('reauth', 'reauth_password');
        $this->assertEqualsCanonicalizing(array_map(TwoFactor::hashRecoveryCode(...), $old), TwoFactor::current($user)->recovery_codes);

        $this->post(self::PAGE.'/codigos', ['reauth_password' => 'password', 'reauth_two_factor_code' => $this->otp($this->secret($user))])->assertRedirect(self::PAGE);
        $stored = TwoFactor::current($user)->recovery_codes;
        $this->assertCount(TwoFactor::RECOVERY_CODES, $stored);
        $this->assertEmpty(array_intersect($stored, array_map(TwoFactor::hashRecoveryCode(...), $old)));
        $this->get(self::PAGE)->assertSee('Guarde os seus códigos de recuperação')->assertDontSee($old[0]);
    }

    public function test_turning_off_asks_who_you_are(): void
    {
        $user = User::factory()->create();
        $this->enable($user);

        $this->actingAs($user)->from(self::PAGE)->delete(self::PAGE)
            ->assertRedirect(self::PAGE)
            ->assertSessionHasErrorsIn('reauth', ['reauth_password' => 'Digite a sua senha para confirmar que é você.']);
        $this->from(self::PAGE)->delete(self::PAGE, ['reauth_password' => 'errada'])
            ->assertSessionHasErrorsIn('reauth', ['reauth_password' => 'A senha não confere.']);
        $this->assertTrue(TwoFactor::enabled($user));

        $this->delete(self::PAGE, ['reauth_password' => 'password', 'reauth_two_factor_code' => $this->otp($this->secret($user))])->assertRedirect(self::PAGE);
        $this->assertFalse(TwoFactor::enabled($user));
        $this->assertDatabaseCount('user_two_factor', 0);
    }

    public function test_cancelling_a_setup_not_confirmed_does_not_ask_for_the_password_but_never_turns_it_off(): void
    {
        $user = User::factory()->create();
        app(TwoFactor::class)->start($user);

        $this->actingAs($user)->delete(self::PAGE.'/configuracao')->assertRedirect(self::PAGE);
        $this->assertDatabaseCount('user_two_factor', 0);

        $this->enable($user);
        $this->delete(self::PAGE.'/configuracao')->assertRedirect(self::PAGE);
        $this->assertTrue(TwoFactor::enabled($user));
    }

    public function test_few_recovery_codes_left_show_a_warning(): void
    {
        $user = User::factory()->create();
        $this->enable($user);
        $record = TwoFactor::current($user);
        $record->forceFill(['recovery_codes' => array_slice($record->recovery_codes, 0, 3)])->save();

        $this->actingAs($user)->get(self::PAGE)->assertSee('Restam só 3 códigos de recuperação.');
    }

    public function test_starting_again_while_on_is_refused_and_keeps_the_secret(): void
    {
        $user = User::factory()->create();
        $this->enable($user);
        $secret = $this->secret($user);

        $this->actingAs($user)->from(self::PAGE)->post(self::PAGE, ['reauth_password' => 'password', 'reauth_two_factor_code' => $this->otp($this->secret($user))])
            ->assertRedirect(self::PAGE)
            ->assertSessionHas('error');
        $this->assertSame($secret, $this->secret($user));
        $this->assertTrue(TwoFactor::enabled($user));
    }

    public function test_one_account_does_not_touch_the_two_factor_of_another(): void
    {
        $owner = User::factory()->create();
        $this->enable($owner);
        $secret = $this->secret($owner);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(self::PAGE)->assertOk()->assertSee('Desligada');
        $this->from(self::PAGE)->post(self::PAGE.'/confirmar', ['two_factor_code' => $this->otp($secret), 'reauth_password' => 'password'])
            ->assertSessionHas('error');
        $this->post(self::PAGE.'/codigos', ['reauth_password' => 'password'])->assertSessionHas('error');
        $this->delete(self::PAGE, ['reauth_password' => 'password'])->assertRedirect(self::PAGE);
        $this->delete(self::PAGE.'/configuracao')->assertRedirect(self::PAGE);

        $this->assertTrue(TwoFactor::enabled($owner));
        $this->assertSame($secret, $this->secret($owner));
        $this->assertFalse(TwoFactor::enabled($intruder));
        $this->assertDatabaseCount('user_two_factor', 1);
    }
}
