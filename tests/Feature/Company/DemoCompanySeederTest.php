<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\OwnedDomain;
use App\Models\User;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** E2a3: os dados de demonstração do laboratório (D13): a empresa fictícia, e nunca em produção. */
class DemoCompanySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
    }

    public function test_it_builds_the_demo_company(): void
    {
        $this->seed(DemoCompanySeeder::class);

        $company = Company::query()->where('name', DemoCompanySeeder::COMPANY)->sole();
        $this->assertNotNull($company->enterprise_enabled_at);
        $this->assertSame(['Financeiro', 'Marketing', 'TI'], $company->sectors()->orderBy('name')->pluck('name')->all());
        $this->assertSame(10, $company->members()->count());
        $this->assertSame(1, $company->members()->whereNotNull('deactivated_at')->count());
        $this->assertSame(['admin' => 1, 'member' => 7, 'sector_manager' => 2], $company->members()->selectRaw('role, count(*) as n')->groupBy('role')->orderBy('role')->pluck('n', 'role')->all());

        $ana = User::query()->where('email', 'ana@demo-acme.example')->sole();
        $this->assertSame(CompanyRole::Admin, $ana->companyRole());
        $this->assertSame(0, $ana->sectors()->count());
        $this->assertSame(['TI'], User::query()->where('email', 'bruno@demo-acme.example')->sole()->managedSectors()->pluck('name')->all());
        $this->assertSame(['Financeiro', 'TI'], User::query()->where('email', 'elisa@demo-acme.example')->sole()->sectors()->orderBy('name')->pluck('name')->all());
        $this->assertSame(9, DB::table('sector_user')->count());

        $this->assertSame(9, AlertProfile::query()->count());
        $this->assertSame(3, LookalikeWatch::query()->count());
        $this->assertSame(3, GithubMonitor::query()->count());
        $this->assertTrue(GithubMonitor::query()->get()->every(fn ($monitor) => $monitor->next_scan_at->isAfter(now()->addMonths(11))));

        // Tudo é da empresa: o da admin sem setor, o das outras no 1º setor (a Íris, sem nenhum, fica sem setor); a posse também.
        foreach ([AlertProfile::class, LookalikeWatch::class, GithubMonitor::class, OwnedDomain::class] as $model) {
            $this->assertSame(0, $model::query()->whereNull('company_id')->count(), $model);
        }
        $this->assertSame([null, 'Financeiro', 'TI'], OwnedDomain::query()->with('sector')->get()->map(fn ($owned) => $owned->sector?->name)->sort()->values()->all());
        $this->assertSame([$company->domain_token], OwnedDomain::query()->distinct()->pluck('proof_token')->all());
        $this->assertSame('TI', AlertProfile::query()->whereHas('user', fn ($q) => $q->where('email', 'elisa@demo-acme.example'))->sole()->sector->name);
        $this->assertNull(AlertProfile::query()->whereHas('user', fn ($q) => $q->where('email', 'iris@demo-acme.example'))->sole()->sector_id);

        // A admin entra com a senha da demonstração e vê os setores.
        $this->post(route('login'), ['email' => 'ana@demo-acme.example', 'password' => DemoCompanySeeder::DEMO_PASSWORD])->assertRedirect();
        $this->get(route('company.sectors'))->assertOk()->assertSee('3 de 10 setores');

        $this->expectExceptionMessage('já existe');
        $this->seed(DemoCompanySeeder::class);
    }

    public function test_it_never_runs_in_production_nor_from_the_database_seeder(): void
    {
        $this->app['env'] = 'production';

        try {
            (new DemoCompanySeeder)->run();
            $this->fail('O seeder de demonstração rodou em produção.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('não roda em produção', $e->getMessage());
        }

        $this->assertSame(0, Company::query()->count());
        $this->assertStringNotContainsString('DemoCompanySeeder', file_get_contents(database_path('seeders/DatabaseSeeder.php')));
    }
}
