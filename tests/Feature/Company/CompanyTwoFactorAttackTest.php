<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Http\Middleware\EnsureCompanyAccountActive;
use App\Http\Middleware\EnsureRecentReauth;
use App\Http\Middleware\EnsureTwoFactorWhenRequired;
use App\Models\Company;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\TwoFactor;
use App\Services\Company\Companies;
use App\Services\Company\CompanyTwoFactor;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Red Team da E1f (2FA obrigatória da empresa e redefinição pela admin, D11). Cada teste descreve o
 * comportamento DESEJADO: os `rt_k*` que falham no fd12a54 provam o achado (ver red-team/e1f/); os
 * "evidência" passam e documentam o que resistiu.
 */
class CompanyTwoFactorAttackTest extends TestCase
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
        $company = app(Companies::class)->create('Acme', 'Admin Acme', 'ana@acme.com');
        app(Companies::class)->enable($company);
        $this->ana = User::query()->where('email', 'ana@acme.com')->sole();
        $this->ana->forceFill(['email_verified_at' => now(), 'password' => 'password'])->save();
        $this->ana->companyMembership->forceFill(['joined_at' => now()])->save();
        [$this->acme, $this->ana] = [$company->fresh(), $this->ana->fresh()];
    }

    private function member(CompanyRole $role = CompanyRole::Member): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->acme->members()->forceCreate(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

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

    // K1 — redefinir a 2FA dos outros não pede a 2FA de quem redefine: com só a senha da admin (roubada), a
    // reautenticação é a senha, e a 2FA de todas as contas da empresa cai (exigir já pede a 2FA dela).
    public function test_rt_k1_a_admin_sem_2fa_nao_redefine_a_dos_outros(): void
    {
        $bruno = $this->member();
        $this->enable($bruno);

        $this->actingAs($this->ana)->from('/empresa/contas')
            ->post(route('company.two-factor.reset'), ['member' => $bruno->id, 'reauth_password' => 'password'])
            ->assertSessionHas('error');

        $this->assertTrue(TwoFactor::enabled($bruno));
    }

    // K2 — a redefinição é a resposta ao celular perdido, e o celular costuma ter o Security Monitor aberto: as sessões e o
    // "Continuar conectado" da conta seguem valendo depois dela (quem achou o celular continua dentro).
    public function test_rt_k2_redefinir_derruba_as_sessoes_e_o_continuar_conectado_da_conta(): void
    {
        config(['session.driver' => 'database']);
        $bruno = $this->member();
        $this->enable($bruno);
        $this->enable($this->ana);
        $bruno->forceFill(['remember_token' => 'do-celular'])->save();
        DB::table('sessions')->insert(['id' => 'celular-perdido', 'user_id' => $bruno->id, 'payload' => '', 'last_activity' => time()]);

        app(CompanyTwoFactor::class)->reset($this->ana, $bruno->id);

        $this->assertDatabaseMissing('sessions', ['id' => 'celular-perdido']);
        $this->assertNotSame('do-celular', $bruno->fresh()->remember_token);
    }

    // Evidência: a ordem — a conta inativa cai (EnsureCompanyAccountActive) antes da 2FA exigida, e a 2FA exigida
    // antes da reautenticação (nenhuma senha é conferida nem gravada antes); o company.admin só dá 404.
    public function test_rt_evidencia_a_ordem_dos_middlewares(): void
    {
        app(Kernel::class); // registra os apelidos e os grupos no roteador
        $stack = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('company.two-factor.reset'));
        $at = fn (string $class) => collect($stack)->search(fn ($m) => is_string($m) && str_starts_with($m, $class));

        $this->assertLessThan($at(EnsureTwoFactorWhenRequired::class), $at(Authenticate::class));
        $this->assertLessThan($at(EnsureTwoFactorWhenRequired::class), $at(EnsureCompanyAccountActive::class));
        $this->assertLessThan($at(EnsureRecentReauth::class), $at(EnsureTwoFactorWhenRequired::class));
    }

    // Evidência: com a exigência, a conta sem 2FA não executa nenhuma rota com login fora da lista aberta.
    public function test_rt_evidencia_nenhuma_rota_com_login_escapa_da_exigencia(): void
    {
        app(Kernel::class); // registra os apelidos e os grupos no roteador
        $bruno = $this->member();
        $this->acme->forceFill(['require_2fa' => true])->save();
        $open = ['account.two-factor', 'account.two-factor.*', 'account.reauth.*', 'logout', 'alerts.tests.runs.stop', 'alerts.tests.runs.status', 'alerts.tests.banner'];
        $checked = 0;
        $escaped = [];

        foreach (Route::getRoutes() as $route) {
            $stack = app('router')->gatherRouteMiddleware($route);

            if (! in_array(EnsureTwoFactorWhenRequired::class, $stack, true) || ($route->getName() && collect($open)->contains(fn ($p) => str($route->getName())->is($p)))) {
                continue;
            }

            $uri = '/'.preg_replace_callback('/\{([^}?]+)\??\}/', fn ($m) => str_contains($route->wheres[$m[1]] ?? '', 'a-f') ? '00000000-0000-4000-8000-000000000000' : '1', $route->uri());
            $response = $this->actingAs($bruno)->call($route->methods()[0], $uri);

            if ($response->getStatusCode() === 302 && $response->headers->get('Location') === route('account.two-factor')) {
                $checked++;
            } elseif (! in_array($response->getStatusCode(), [404, 405], true)) {
                $escaped[] = $route->methods()[0].' '.$uri.' → '.$response->getStatusCode();
            }
        }

        $this->assertSame([], $escaped);
        $this->assertGreaterThan(50, $checked);
    }

    // Evidência (D11): só dentro da empresa; o responsável não redefine; a conta sem 2FA não "é redefinida".
    public function test_rt_evidencia_redefinir_so_a_admin_e_so_contas_da_empresa(): void
    {
        $this->enable($this->ana);
        $outsider = User::factory()->create();
        $this->enable($outsider);
        $carla = $this->member();

        $this->expectException(ActionRefused::class);

        try {
            app(CompanyTwoFactor::class)->reset($this->ana, $outsider->id);
            $this->fail('redefiniu uma conta pessoal');
        } catch (ModelNotFoundException) {
            $this->assertTrue(TwoFactor::enabled($outsider));
        }

        app(CompanyTwoFactor::class)->reset($this->ana, $carla->id);
    }
}
