<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendEmail;
use App\Mail\CompanyTwoFactorRequired;
use App\Mail\TwoFactorChanged;
use App\Models\Company;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\TwoFactor;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * A 2FA obrigatória da empresa (E1f, R13) e a redefinição pela admin (D11):
 * exigida, a conta sem a 2FA só chega à tela de configurar; só quem
 * administra liga a exigência e redefine, sempre dentro da própria empresa.
 */
class CompanyTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        config(['secmon.enterprise.enabled' => true, 'secmon.two_factor.enabled' => true]);
        Queue::fake();
        $this->travelTo(now()->setTime(12, 0, 15));
        [$this->acme, $this->ana] = $this->company('Acme', 'ana@acme.com');
    }

    /** @return array{Company, User} uma empresa ativa e a admin dela */
    private function company(string $name, string $email): array
    {
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);
        $admin = User::query()->where('email', $email)->sole();
        $admin->forceFill(['email_verified_at' => now(), 'password' => 'password'])->save();
        $admin->companyMembership->forceFill(['joined_at' => now()])->save();

        return [$company->fresh(), $admin->fresh()];
    }

    private function member(Company $company, CompanyRole $role = CompanyRole::Member): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $company->members()->forceCreate(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    }

    private function otp(User $user): string
    {
        $secret = UserTwoFactor::where('user_id', $user->id)->firstOrFail()->secret;

        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    }

    private function enable(User $user): void
    {
        app(TwoFactor::class)->start($user);
        app(TwoFactor::class)->confirm($user, $this->otp($user));
        $this->travel(1)->minutes();
    }

    /** A senha e o código do app da admin (a reautenticação de quem tem a 2FA). */
    private function confirm(User $user): array
    {
        return ['reauth_password' => 'password', 'reauth_two_factor_code' => $this->otp($user)];
    }

    private function require(): void
    {
        $this->acme->forceFill(['require_2fa' => true])->save();
    }

    public function test_required_an_account_without_two_factor_only_reaches_the_setup(): void
    {
        $bruno = $this->member($this->acme);
        $this->require();
        $this->actingAs($bruno);

        foreach (['/conta', '/alertas'] as $path) {
            $this->get($path)->assertRedirect(route('account.two-factor'))->assertSessionHas('warning');
        }
        // O aviso em âmbar (um passo esperado), sem o "← Minha conta" que só voltaria para cá.
        $this->get(route('account.two-factor'))->assertOk()->assertSee('A sua empresa exige a verificação em duas etapas')
            ->assertSee('bg-amber-950/30', false)->assertDontSee('← Minha conta');

        $this->enable($bruno);
        $this->get('/conta')->assertOk();

        // Nem a admin sem a 2FA (desligou a dela depois de exigir) abre o /empresa.
        $this->actingAs($this->ana)->get('/empresa/contas')->assertRedirect(route('account.two-factor'));
    }

    public function test_an_account_with_two_factor_and_a_company_that_does_not_require_it_see_no_change(): void
    {
        $bruno = $this->member($this->acme);
        $this->actingAs($bruno)->get('/conta')->assertOk();

        $this->enable($this->ana);
        $this->require();
        $this->actingAs($this->ana)->get('/empresa/contas')->assertOk()->assertSee('Exigida');
    }

    public function test_the_admin_requires_it_with_reauth_and_the_accounts_without_it_get_an_email(): void
    {
        $bruno = $this->member($this->acme);
        $carla = $this->member($this->acme);
        $this->enable($carla);
        $this->actingAs($this->ana);

        // Sem a 2FA da própria admin, não exige.
        $this->from('/empresa/contas')->post(route('company.two-factor.update'), ['required' => 1, 'reauth_password' => 'password'])->assertSessionHas('error');
        $this->assertFalse($this->acme->fresh()->require_2fa);

        $this->enable($this->ana);
        $this->flushSession();
        $this->actingAs($this->ana)->from('/empresa/contas')->post(route('company.two-factor.update'), ['required' => 1])->assertSessionHasErrorsIn('reauth', 'reauth_password');
        $this->assertFalse($this->acme->fresh()->require_2fa);

        $this->from('/empresa/contas')->post(route('company.two-factor.update'), ['required' => 1] + $this->confirm($this->ana))->assertSessionHas('status');
        $this->assertTrue($this->acme->fresh()->require_2fa);

        $warned = Queue::pushed(SendEmail::class)->filter(fn (SendEmail $job) => $job->mail instanceof CompanyTwoFactorRequired)->map->mail;
        $this->assertSame([$bruno->name], $warned->map->name->values()->all(), 'só a conta sem a 2FA recebe o aviso');

        $this->post(route('company.two-factor.update'), ['required' => 0]);
        $this->assertFalse($this->acme->fresh()->require_2fa);
    }

    public function test_required_the_account_does_not_turn_its_own_two_factor_off(): void
    {
        $bruno = $this->member($this->acme);
        $this->enable($bruno);
        $this->require();

        $this->actingAs($bruno)->get(route('account.two-factor'))->assertOk()->assertSee('não pode ser desligada')->assertDontSee('Desligar a verificação em duas etapas?');
        $this->from(route('account.two-factor'))->delete(route('account.two-factor.disable'), $this->confirm($bruno))
            ->assertSessionHas('error');
        $this->assertTrue(TwoFactor::enabled($bruno));
    }

    public function test_an_admin_without_two_factor_sees_why_she_cannot_require_it(): void
    {
        $this->actingAs($this->ana)->get('/empresa/contas')->assertOk()
            ->assertSee('Ligue antes a sua verificação em duas etapas')->assertDontSee('name="required" value="1"', false);
    }

    public function test_a_member_neither_requires_nor_resets(): void
    {
        $bruno = $this->member($this->acme, CompanyRole::SectorManager);
        $carla = $this->member($this->acme);
        $this->enable($bruno);
        $this->enable($carla);
        $this->actingAs($bruno);

        $this->post(route('company.two-factor.update'), ['required' => 1] + $this->confirm($bruno))->assertNotFound();
        $this->post(route('company.two-factor.reset'), ['member' => $carla->id] + $this->confirm($bruno))->assertNotFound();
        $this->assertFalse($this->acme->fresh()->require_2fa);
        $this->assertTrue(TwoFactor::enabled($carla));
    }

    public function test_the_admin_resets_the_two_factor_of_an_account_that_lost_the_phone(): void
    {
        $bruno = $this->member($this->acme);
        $this->enable($bruno);
        $this->enable($this->ana);
        $this->require();

        $this->actingAs($this->ana)->from('/empresa/contas')
            ->post(route('company.two-factor.reset'), ['member' => $bruno->id] + $this->confirm($this->ana))
            ->assertSessionHas('status');

        $this->assertFalse(TwoFactor::enabled($bruno));
        Queue::assertPushed(SendEmail::class, fn (SendEmail $job) => $job->mail instanceof TwoFactorChanged && $job->mail->event === 'reset' && $job->mail->name === $bruno->name);

        // Com a empresa exigindo, a conta configura de novo no próximo acesso.
        $this->actingAs($bruno)->get('/conta')->assertRedirect(route('account.two-factor'));
    }

    public function test_requiring_and_resetting_are_in_the_company_activity_and_csv(): void
    {
        $bruno = $this->member($this->acme);
        $this->enable($bruno);
        $this->enable($this->ana);
        $this->actingAs($this->ana);

        $this->post(route('company.two-factor.update'), ['required' => 1] + $this->confirm($this->ana));
        $this->post(route('company.two-factor.reset'), ['member' => $bruno->id]);
        $this->post(route('company.two-factor.update'), ['required' => 0]);

        $this->get(route('company.activity'))->assertOk()
            ->assertSee('Verificação em duas etapas exigida de todas as contas')
            ->assertSee('Verificação em duas etapas redefinida: '.$bruno->name)
            ->assertSee('Verificação em duas etapas voltou a ser opcional');
        $csv = $this->get(route('company.activity.csv', ['tipo' => 'two_factor.reset']))->streamedContent();
        $this->assertStringContainsString('Verificação em duas etapas redefinida: '.$bruno->name, $csv);
        $this->assertStringContainsString($this->ana->name, $csv);
        $this->assertStringNotContainsString('exigida de todas as contas', $csv);
    }

    public function test_the_reset_stays_inside_the_company_and_never_on_the_admin_own(): void
    {
        [, $zeca] = $this->company('Zeta', 'zeca@zeta.com');
        $bruno = $this->member($this->acme);
        $this->enable($bruno);
        $this->enable($zeca);
        $this->enable($this->ana);

        // A admin de outra empresa: 404, e a 2FA do Bruno segue ligada.
        $this->actingAs($zeca)->post(route('company.two-factor.reset'), ['member' => $bruno->id] + $this->confirm($zeca))->assertNotFound();
        $this->assertTrue(TwoFactor::enabled($bruno));

        // A própria admin, pelo atalho: recusado.
        $this->actingAs($this->ana)->from('/empresa/contas')
            ->post(route('company.two-factor.reset'), ['member' => $this->ana->id] + $this->confirm($this->ana))
            ->assertSessionHas('error');
        $this->assertTrue(TwoFactor::enabled($this->ana));
    }
}
