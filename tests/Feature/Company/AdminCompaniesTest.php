<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → Empresas (E1b): listar com o uso, criar com a admin, limites, suspender e a chave da empresa. */
class AdminCompaniesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function company(string $name = 'Acme', string $email = 'ana@acme.com'): Company
    {
        return app(Companies::class)->create($name, 'Ana Admin', $email);
    }

    /** @return list<array{string, string}> */
    private function routes(Company $company): array
    {
        return [
            ['get', route('admin.companies.index')],
            ['post', route('admin.companies.store')],
            ['put', route('admin.companies.limits', $company)],
            ['post', route('admin.companies.suspend', $company)],
            ['post', route('admin.companies.resume', $company)],
            ['post', route('admin.companies.enable', $company)],
            ['post', route('admin.companies.disable', $company)],
            ['post', route('admin.companies.entry', $company)],
        ];
    }

    public function test_without_the_global_key_everything_is_404_and_the_menu_hides_it(): void
    {
        $company = $this->company();
        $admin = $this->admin();
        config(['secmon.enterprise.enabled' => false]);

        foreach ($this->routes($company) as [$method, $url]) {
            $this->actingAs($admin)->{$method}($url, ['name' => 'X', 'admin_name' => 'X', 'admin_email' => 'x@x.com'])->assertNotFound();
        }
        // Nem um id que não existe responde diferente.
        $this->actingAs($admin)->post('/admin/empresas/999999/suspender')->assertNotFound();

        $this->assertNull($company->fresh()->suspended_at);
        $this->assertSame(1, Company::query()->count());
        $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertDontSee('/admin/empresas');
    }

    public function test_only_the_secmon_admin_gets_in(): void
    {
        $company = $this->company();
        // A conta de empresa (com a empresa desligada) nem chega ao /admin: a sessão cai antes (CompanyEntryTest).
        foreach ([User::factory()->create()] as $user) {
            foreach ($this->routes($company) as [$method, $url]) {
                $this->actingAs($user)->{$method}($url, ['name' => 'X', 'admin_name' => 'X', 'admin_email' => 'x@x.com'])->assertForbidden();
            }
        }

        auth()->logout();
        $this->get(route('admin.companies.index'))->assertRedirect(route('admin.login'));
        $this->assertNull($company->fresh()->enterprise_enabled_at);
        $this->assertSame(1, Company::query()->count());
    }

    public function test_the_list_shows_the_admin_and_the_usage_against_the_limits(): void
    {
        $company = $this->company();
        $company->members()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => CompanyRole::Member]);
        $company->members()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => CompanyRole::Member, 'deactivated_at' => now()]);
        $company->forceFill(['limit_accounts' => 12])->save();

        $this->actingAs($this->admin())->get(route('admin.companies.index'))
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('ana@acme.com')
            ->assertSee('ainda não entrou')
            ->assertSee('chave desligada')
            ->assertSeeInOrder(['Contas', '2', '/ 12', 'Domínios', '0', '/ 15', 'Subdomínios', '0', '/ 20', 'no domínio mais cheio', 'Setores', '0', '/ 10', 'Alertas de Notícias', '0', '/ 20'])
            ->assertSee('placeholder="padrão 300"', false)
            ->assertDontSee($company->domain_token);
    }

    public function test_the_admin_creates_the_company_with_its_admin_account(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.companies.store'), ['name' => 'Acme', 'admin_name' => 'Ana', 'admin_email' => 'Ana@Acme.com'])
            ->assertRedirect(route('admin.companies.index'))
            ->assertSessionHas('status');

        $ana = User::query()->where('email', 'ana@acme.com')->sole();
        $this->assertSame('Acme', $ana->company->name);
        $this->assertSame(CompanyRole::Admin, $ana->companyRole());
        $this->assertFalse((bool) $ana->is_admin);
        $this->assertFalse(app(Companies::class)->isActive($ana->company));
    }

    public function test_the_creation_refuses_an_existing_account_and_bad_input(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'pessoa@acme.com']);

        $this->actingAs($admin)->from(route('admin.companies.index'))
            ->post(route('admin.companies.store'), ['name' => 'Acme', 'admin_name' => 'Pessoa', 'admin_email' => 'PESSOA@acme.com'])
            ->assertRedirect(route('admin.companies.index'))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'outro e-mail'));

        // O e-mail do próprio admin do Security Monitor também é uma conta que já existe.
        $this->actingAs($admin)->post(route('admin.companies.store'), ['name' => 'Acme', 'admin_name' => 'Eu', 'admin_email' => $admin->email])
            ->assertSessionHas('error');

        $this->actingAs($admin)->post(route('admin.companies.store'), ['name' => str_repeat('a', 121), 'admin_name' => '', 'admin_email' => 'não'])
            ->assertSessionHasErrors(['name', 'admin_name', 'admin_email']);

        $this->assertSame(0, Company::query()->count());
    }

    public function test_the_limits_are_saved_at_the_edges(): void
    {
        $company = $this->company();
        $admin = $this->admin();
        $limits = array_fill_keys(Company::LIMITS, '');

        // Vazio = o padrão; 0 vale; o de contas pode ficar igual às contas que a empresa já tem (1).
        $this->actingAs($admin)->put(route('admin.companies.limits', $company), ['limits' => ['accounts' => '1', 'webhooks' => '0', 'radar_seeds' => '100000'] + $limits])
            ->assertRedirect(route('admin.companies.index'));
        $company->refresh();
        $this->assertSame(1, $company->limit_accounts);
        $this->assertSame(0, $company->limit_webhooks);
        $this->assertSame(100000, $company->limit_radar_seeds);
        $this->assertNull($company->limit_domains);
        $this->assertSame(15, app(Companies::class)->limit($company, 'domains'));

        foreach ([['webhooks' => '-1'], ['domains' => 'dez'], ['sectors' => '100001'], ['accounts' => '2.5']] as $bad) {
            $this->actingAs($admin)->put(route('admin.companies.limits', $company), ['limits' => $bad + $limits])
                ->assertSessionHasErrors('limits.'.array_key_first($bad));
        }

        // Abaixo das contas que já existem, não.
        $this->actingAs($admin)->put(route('admin.companies.limits', $company), ['limits' => ['accounts' => '0'] + $limits])
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'já tem 1 conta'));

        // Tudo vazio volta ao padrão.
        $this->actingAs($admin)->put(route('admin.companies.limits', $company), ['limits' => $limits])->assertSessionHasNoErrors();
        $company->refresh();
        $this->assertNull($company->limit_accounts);
        $this->assertSame(10, app(Companies::class)->limit($company, 'accounts'));
        $this->assertSame(300, app(Companies::class)->limit($company, 'radar_seeds'));
    }

    public function test_the_company_key_and_the_suspension(): void
    {
        $company = $this->company();
        $admin = $this->admin();
        $companies = app(Companies::class);

        $this->actingAs($admin)->post(route('admin.companies.enable', $company))->assertRedirect(route('admin.companies.index'));
        $this->assertTrue($companies->isActive($company->fresh()));
        $since = $company->fresh()->enterprise_enabled_at;

        // Repetir não muda a data.
        $this->travel(1)->hours();
        $this->actingAs($admin)->post(route('admin.companies.enable', $company));
        $this->assertTrue($since->equalTo($company->fresh()->enterprise_enabled_at));

        $this->actingAs($admin)->post(route('admin.companies.suspend', $company))->assertSessionHas('status');
        $this->assertNotNull($company->fresh()->suspended_at);
        $this->assertFalse($companies->isActive($company->fresh()));
        $this->actingAs($admin)->get(route('admin.companies.index'))->assertSee('suspensa desde')->assertSee(route('admin.companies.resume', $company));

        $this->actingAs($admin)->post(route('admin.companies.resume', $company));
        $this->assertTrue($companies->isActive($company->fresh()));

        $this->actingAs($admin)->post(route('admin.companies.disable', $company));
        $this->assertNull($company->fresh()->enterprise_enabled_at);
        $this->assertFalse($companies->isActive($company->fresh()));
        $this->actingAs($admin)->get(route('admin.companies.index'))->assertSee('chave desligada')->assertSee(route('admin.companies.enable', $company));
    }

    public function test_a_company_account_is_not_removed_from_the_users_page(): void
    {
        $company = $this->company();
        $ana = User::query()->where('email', 'ana@acme.com')->sole();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertOk()
            ->assertSee('admin da empresa Acme')
            ->assertSee('ainda não entrou')
            ->assertSee('Contas de empresa não têm')
            ->assertSee('href="'.route('admin.companies.index').'"', false)
            ->assertDontSee('action="'.route('admin.users.destroy', $ana).'"', false);

        $this->actingAs($admin)->from(route('admin.users'))->delete(route('admin.users.destroy', $ana))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'conta da empresa Acme'));
        $this->assertTrue($ana->fresh()->isCompanyAccount());
        $this->assertTrue($company->members()->exists());

        // A conta pessoal continua saindo por aqui.
        $personal = User::factory()->create();
        $this->actingAs($admin)->delete(route('admin.users.destroy', $personal))->assertSessionHas('status');
        $this->assertNull($personal->fresh());
    }
}
