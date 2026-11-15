<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Red Team da E1b (Admin → Empresas). Os "rt" falham na ponta da branch; os "evidência" passam. */
class RedTeamE1bTest extends TestCase
{
    use RefreshDatabase;

    private User $secmon;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        $this->secmon = User::factory()->create(['is_admin' => true]);
    }

    /** @param  array<string, int|string|null>  $limits */
    private function limits(Company $company, array $limits)
    {
        $all = array_fill_keys(Company::LIMITS, null);

        return $this->actingAs($this->secmon)->from(route('admin.companies.index'))->put(route('admin.companies.limits', $company), ['limits' => array_merge($all, $limits)]);
    }

    // C1 — "vazio = o padrão" passa por baixo da regra: com 12 contas, o limite volta a 10.
    public function test_rt_c1_voltar_ao_padrao_nao_deixa_o_limite_abaixo_das_contas(): void
    {
        $acme = app(Companies::class)->create('Acme', 'Ana', 'ana@acme.com');
        $this->limits($acme, ['accounts' => 12]);
        foreach (range(1, 11) as $i) {
            $acme->members()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => CompanyRole::Member]);
        }

        $this->limits($acme, ['accounts' => null]);

        $acme->refresh();
        $this->assertGreaterThanOrEqual(12, app(Companies::class)->limit($acme, 'accounts'), 'O limite de contas ficou abaixo das 12 contas da empresa.');
    }

    // C2 — e-mail com acento passa no "email" do form request e estoura o FILTER_VALIDATE_EMAIL do serviço: 500.
    public function test_rt_c2_email_unicode_da_admin_e_erro_de_validacao_e_nao_500(): void
    {
        $this->actingAs($this->secmon)->from(route('admin.companies.index'))
            ->post(route('admin.companies.store'), ['name' => 'Acme', 'admin_name' => 'Ana', 'admin_email' => 'ána@acme.com'])
            ->assertRedirect(route('admin.companies.index'))
            ->assertSessionHasErrors('admin_email');

        $this->assertSame(0, Company::query()->count());
    }

    // Evidência — só o admin do Security Monitor: conta pessoal, admin de empresa e visitante não entram em nenhuma rota.
    public function test_evidencia_so_o_admin_do_secmon(): void
    {
        $acme = app(Companies::class)->create('Acme', 'Ana', 'ana@acme.com');
        $ana = User::query()->where('email', 'ana@acme.com')->sole();
        $routes = [
            ['get', route('admin.companies.index')], ['post', route('admin.companies.store')], ['put', route('admin.companies.limits', $acme)],
            ['post', route('admin.companies.suspend', $acme)], ['post', route('admin.companies.resume', $acme)],
            ['post', route('admin.companies.enable', $acme)], ['post', route('admin.companies.disable', $acme)],
        ];

        foreach ([User::factory()->create(), $ana] as $who) {
            foreach ($routes as [$method, $url]) {
                $this->actingAs($who)->{$method}($url, ['limits' => ['accounts' => 0], 'name' => 'X', 'admin_name' => 'X', 'admin_email' => 'x@x.com'])->assertForbidden();
            }
        }

        auth()->logout();
        $this->post(route('admin.companies.suspend', $acme))->assertRedirect(route('admin.login'));
        $this->assertNull($acme->fresh()->suspended_at);
        $this->assertNull($acme->fresh()->limit_accounts);
    }

    // Evidência — repetir suspender e ligar não muda a data; id inexistente 404; limites fora da borda recusados.
    public function test_evidencia_repetir_e_bordas(): void
    {
        $acme = app(Companies::class)->create('Acme', 'Ana', 'ana@acme.com');
        $this->actingAs($this->secmon)->post(route('admin.companies.suspend', $acme));
        $first = $acme->fresh()->suspended_at;
        $this->travel(5)->minutes();
        $this->post(route('admin.companies.suspend', $acme));
        $this->assertEquals($first, $acme->fresh()->suspended_at);

        $this->post(route('admin.companies.enable', $acme));
        $on = $acme->fresh()->enterprise_enabled_at;
        $this->travel(5)->minutes();
        $this->post(route('admin.companies.enable', $acme));
        $this->assertEquals($on, $acme->fresh()->enterprise_enabled_at);

        $this->post('/admin/empresas/999999/suspender')->assertNotFound();

        foreach ([-1, 100001, 'abc', ['x'], '1e3'] as $bad) {
            $this->limits($acme, ['accounts' => $bad])->assertSessionHasErrors();
        }
        // Abaixo da admin, recusado; na borda (1 conta, 1 de limite), aceito.
        $this->limits($acme, ['accounts' => 0]);
        $this->assertNull($acme->fresh()->limit_accounts);
        $this->limits($acme, ['accounts' => 1]);
        $this->assertSame(1, $acme->fresh()->limit_accounts);
    }
}
