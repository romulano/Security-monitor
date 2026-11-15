<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\TwoFactor;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * A 2FA no login × a conta de empresa: a empresa inativa responde como senha
 * errada e nunca abre o desafio do código; a ativa passa pelo desafio; a
 * suspensa entre a senha (ou o GitHub) e o código não entra.
 */
class TwoFactorCompanyLoginTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '/login/codigo';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'secmon.two_factor.enabled' => true,
            'secmon.enterprise.enabled' => true,
            'services.github.client_id' => 'Iv1.abc',
            'services.github.client_secret' => 'segredo',
        ]);
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
            'api.github.com/user/emails' => Http::response([['email' => 'ana@acme.com', 'primary' => true, 'verified' => true]]),
            'api.github.com/user' => Http::response(['id' => 77, 'login' => 'ana', 'name' => 'Ana']),
            'api.github.com/applications/*' => Http::response('', 204),
        ]);
        $this->travelTo(now()->setTime(12, 0, 15));
    }

    private function otp(User $user): string
    {
        $secret = UserTwoFactor::where('user_id', $user->id)->firstOrFail()->secret;

        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    }

    /** A admin da Acme com a 2FA ligada; a empresa ativa ou não. */
    private function ana(bool $active): User
    {
        $acme = app(Companies::class)->create('Acme', 'Ana', 'ana@acme.com');
        $acme->forceFill(['enterprise_enabled_at' => $active ? now() : null])->save();
        $ana = User::query()->where('email', 'ana@acme.com')->sole();
        $ana->forceFill(['password' => 'senha-da-ana-123', 'email_verified_at' => now()])->save();
        app(TwoFactor::class)->start($ana);
        app(TwoFactor::class)->confirm($ana, $this->otp($ana));
        $this->travel(1)->minutes();

        return $ana->fresh();
    }

    private function password()
    {
        return $this->post(route('login'), ['email' => 'ana@acme.com', 'password' => 'senha-da-ana-123']);
    }

    private function github()
    {
        parse_str(parse_url($this->get(route('login.github'))->headers->get('Location'), PHP_URL_QUERY), $query);

        return $this->get(route('login.github.callback', ['code' => 'c', 'state' => $query['state']]));
    }

    private function suspend(): void
    {
        Company::query()->sole()->forceFill(['suspended_at' => now()])->save();
    }

    public function test_an_inactive_company_answers_as_a_wrong_password_and_never_opens_the_challenge(): void
    {
        $this->ana(active: false);

        $this->password()->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);
        $this->assertGuest();
        $this->get(self::CODE)->assertRedirect(route('login'));

        $this->github();
        $this->assertGuest();
        $this->get(self::CODE)->assertRedirect(route('login'));
    }

    public function test_an_active_company_goes_through_the_challenge(): void
    {
        $ana = $this->ana(active: true);

        $this->password()->assertRedirect(self::CODE);
        $this->assertGuest();
        $this->post(self::CODE, ['two_factor_code' => $this->otp($ana)]);
        $this->assertAuthenticatedAs($ana);
        $this->post('/logout');

        $this->travel(1)->minutes();
        $this->github()->assertRedirect(self::CODE);
        $this->assertGuest();
        $this->post(self::CODE, ['two_factor_code' => $this->otp($ana)]);
        $this->assertAuthenticatedAs($ana);
    }

    public function test_a_company_suspended_between_the_password_and_the_code_does_not_enter(): void
    {
        $ana = $this->ana(active: true);

        $this->password()->assertRedirect(self::CODE);
        $this->suspend();
        $this->post(self::CODE, ['two_factor_code' => $this->otp($ana)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_company_suspended_between_github_and_the_code_does_not_enter(): void
    {
        $ana = $this->ana(active: true);

        $this->github()->assertRedirect(self::CODE);
        $this->suspend();
        $this->post(self::CODE, ['two_factor_code' => $this->otp($ana)])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($ana->fresh()->github_id, 'a ligação ao GitHub não foi gravada');
    }
}
