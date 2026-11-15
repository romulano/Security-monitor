<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** A estrutura do plano empresarial (E1a): a empresa, as contas dela, os limites e as chaves. */
class CompaniesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_12_08_000100_create_companies_tables.php';

    private function companies(): Companies
    {
        return app(Companies::class);
    }

    /** O banco recusa (numa transação própria, para o teste seguir depois do erro). */
    private function assertRefusedByDatabase(callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('O banco aceitou o que devia recusar.');
    }

    public function test_the_company_is_born_with_its_admin_account(): void
    {
        $company = $this->companies()->create('  Acme Ltda ', 'Ana Admin', '  Ana@Acme.COM.br ');

        $this->assertSame('Acme Ltda', $company->name);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $company->domain_token);
        $this->assertFalse($company->require_2fa);
        $this->assertNull($company->enterprise_enabled_at);
        $this->assertNull($company->suspended_at);

        $admin = User::query()->where('email', 'ana@acme.com.br')->sole();
        $this->assertSame('Ana Admin', $admin->name);
        $this->assertFalse((bool) $admin->is_admin);
        $this->assertNull($admin->email_verified_at);
        $this->assertNull($admin->domain_token);
        // Ninguém conhece a senha: a admin define a dela pelo link do painel.
        $this->assertTrue(Hash::isHashed($admin->password));
        $this->assertFalse(Hash::check('password', $admin->password));

        $this->assertTrue($admin->isCompanyAccount());
        $this->assertSame(CompanyRole::Admin, $admin->companyRole());
        $this->assertTrue($admin->company->is($company));
        $this->assertNotNull($admin->companyMembership->invited_at);
        $this->assertNull($admin->companyMembership->joined_at);
        $this->assertNull($admin->companyMembership->deactivated_at);
        $this->assertSame([$admin->id], $company->users()->pluck('users.id')->all());
    }

    public function test_each_company_has_its_own_token_different_from_the_account_tokens(): void
    {
        $tokens = collect(range(1, 5))->map(fn ($i) => $this->companies()->create("Empresa {$i}", 'Admin', "admin{$i}@empresa.com")->domain_token);

        $this->assertCount(5, $tokens->unique());
        $this->assertSame(0, DB::table('users')->whereIn('domain_token', $tokens)->count());
        $this->assertRefusedByDatabase(fn () => DB::table('companies')->insert(['name' => 'Cópia', 'domain_token' => $tokens[0], 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_an_existing_personal_account_never_becomes_the_admin(): void
    {
        $personal = User::factory()->create(['email' => 'pessoa@acme.com']);

        try {
            $this->companies()->create('Acme', 'Pessoa', 'PESSOA@acme.com');
            $this->fail('A conta pessoal virou admin de empresa.');
        } catch (ActionRefused $e) {
            $this->assertStringContainsString('outro e-mail', $e->getMessage());
        }

        $this->assertSame(0, Company::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertFalse($personal->fresh()->isCompanyAccount());
    }

    public function test_the_creation_is_all_or_nothing(): void
    {
        CompanyMember::creating(fn () => throw new RuntimeException('falha no meio'));

        try {
            $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
            $this->fail('A criação não falhou.');
        } catch (RuntimeException $e) {
            $this->assertSame('falha no meio', $e->getMessage());
        } finally {
            CompanyMember::flushEventListeners();
        }

        $this->assertSame(0, Company::query()->count());
        $this->assertFalse(User::query()->where('email', 'ana@acme.com')->exists());
    }

    public function test_the_creation_requires_the_names_and_a_valid_email(): void
    {
        foreach ([['', 'Ana', 'ana@acme.com'], ['Acme', ' ', 'ana@acme.com'], ['Acme', 'Ana', 'não é e-mail']] as [$name, $admin, $email]) {
            try {
                $this->companies()->create($name, $admin, $email);
                $this->fail('Criou sem os dados.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_an_account_belongs_to_at_most_one_company(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $other = $this->companies()->create('Outra', 'Beto', 'beto@outra.com');
        $ana = User::query()->where('email', 'ana@acme.com')->sole();

        $this->assertRefusedByDatabase(fn () => $other->members()->forceCreate(['user_id' => $ana->id, 'role' => CompanyRole::Member]));
        $this->assertRefusedByDatabase(fn () => $acme->members()->forceCreate(['user_id' => $ana->id, 'role' => CompanyRole::Member]));
        $this->assertSame(1, CompanyMember::query()->where('user_id', $ana->id)->count());
        $this->assertTrue($ana->fresh()->company->is($acme));
    }

    public function test_a_company_has_one_admin_and_only_the_known_roles(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');

        $this->assertRefusedByDatabase(fn () => $acme->members()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => CompanyRole::Admin]));
        $this->assertRefusedByDatabase(fn () => DB::table('company_user')->insert(['company_id' => $acme->id, 'user_id' => User::factory()->create()->id, 'role' => 'owner']));

        $manager = User::factory()->create();
        $acme->members()->forceCreate(['user_id' => $manager->id, 'role' => CompanyRole::SectorManager]);
        $this->assertSame(CompanyRole::SectorManager, $manager->companyRole());
        $this->assertSame(1, $acme->members()->where('role', 'admin')->count());
    }

    public function test_a_company_with_accounts_is_not_deleted_and_a_deleted_account_leaves_it(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');

        // Sem a linha em company_user a conta pareceria pessoal: a exclusão da empresa trata as contas antes.
        $this->assertRefusedByDatabase(fn () => $acme->delete());
        $this->assertTrue($acme->fresh()->members()->exists());

        User::query()->where('email', 'ana@acme.com')->sole()->delete();
        $this->assertFalse($acme->members()->exists());
        // A auditoria (E1e) também segura a empresa: excluir a empresa é a E6b, com a retenção dela.
        $this->assertRefusedByDatabase(fn () => $acme->delete());
        $this->assertSame(1, Company::query()->count());
    }

    public function test_the_limits_are_the_config_defaults_until_the_company_gets_its_own(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $expected = [
            'accounts' => 10, 'domains' => 15, 'subdomains' => 20, 'sectors' => 10, 'github_monitors' => 5,
            'alert_profiles' => 20, 'sector_channels' => 5, 'general_channels' => 5, 'webhooks' => 10, 'radar_seeds' => 300,
            'daily_invites' => 50,
        ];

        $this->assertSame(array_keys($expected), Company::LIMITS);
        $this->assertSame($expected, config('secmon.enterprise.limits'));

        foreach ($expected as $name => $value) {
            $this->assertTrue(Schema::hasColumn('companies', 'limit_'.$name), $name);
            $this->assertNull($acme->getAttribute('limit_'.$name));
            $this->assertSame($value, $this->companies()->limit($acme, $name), $name);
        }

        // A sobrescrita vale só para a empresa; o padrão da config muda sem migrar nada.
        $acme->forceFill(['limit_accounts' => 12, 'limit_webhooks' => 0])->save();
        $other = $this->companies()->create('Outra', 'Beto', 'beto@outra.com');
        config(['secmon.enterprise.limits.domains' => 30]);

        $acme = $acme->fresh();
        $this->assertSame(12, $this->companies()->limit($acme, 'accounts'));
        $this->assertSame(0, $this->companies()->limit($acme, 'webhooks'));
        $this->assertSame(30, $this->companies()->limit($acme, 'domains'));
        $this->assertSame(10, $this->companies()->limit($other, 'accounts'));
    }

    public function test_a_negative_or_unknown_limit_is_refused(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');

        $this->assertRefusedByDatabase(fn () => DB::table('companies')->where('id', $acme->id)->update(['limit_domains' => -1]));

        $this->expectException(InvalidArgumentException::class);
        $this->companies()->limit($acme, 'domain_token');
    }

    public function test_the_company_is_active_only_with_both_keys_and_not_suspended(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');

        // Padrão: a chave global desligada.
        $this->assertFalse($this->companies()->enabled());
        $acme->forceFill(['enterprise_enabled_at' => now()])->save();
        $this->assertFalse($this->companies()->isActive($acme));

        config(['secmon.enterprise.enabled' => true]);
        $this->assertTrue($this->companies()->isActive($acme));

        $acme->forceFill(['suspended_at' => now()])->save();
        $this->assertTrue($acme->isSuspended());
        $this->assertFalse($this->companies()->isActive($acme));

        // A chave da empresa desligada: com a global ligada, as outras empresas não entram.
        $other = $this->companies()->create('Outra', 'Beto', 'beto@outra.com');
        $this->assertFalse($this->companies()->isActive($other));
    }

    public function test_a_personal_account_stays_personal(): void
    {
        $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $personal = User::factory()->create();

        $this->assertFalse($personal->isCompanyAccount());
        $this->assertNull($personal->companyRole());
        $this->assertNull($personal->company);
        $this->assertNull($personal->companyMembership);
    }

    public function test_the_token_is_not_serialized(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');

        $this->assertArrayNotHasKey('domain_token', $acme->toArray());
        $this->assertStringNotContainsString($acme->domain_token, $acme->toJson());
    }

    public function test_the_migration_goes_down_and_up(): void
    {
        $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $migration = require database_path(self::MIGRATION);
        // Os convites (E1c) dependem das empresas: descem antes e sobem depois, como no rollback.
        $invites = require database_path('migrations/2026_12_09_000100_create_company_invites_table.php');
        $audit = require database_path('migrations/2026_12_10_000100_create_company_audit_table.php');
        // Os setores (E2a) também.
        $sectors = require database_path('migrations/2026_12_11_000100_create_sectors_tables.php');
        // E as colunas de empresa e setor dos monitoramentos (E2b0), que apontam para as duas.
        $monitors = require database_path('migrations/2026_12_12_000100_add_company_and_sector_to_monitors.php');
        // E a posse da empresa (E3a0), que também aponta para as duas.
        $owned = require database_path('migrations/2026_12_14_000100_add_company_and_sector_to_owned_domains.php');
        // E os avisos de limite (E3d).
        $notices = require database_path('migrations/2026_12_15_000100_create_company_limit_notices_table.php');
        // E os canais da empresa (E5a0), que apontam para as duas.
        $channels = require database_path('migrations/2026_12_18_000100_add_company_and_sector_to_notification_channels.php');

        $channels->down();
        $notices->down();
        $owned->down();
        $monitors->down();
        $sectors->down();
        $this->assertFalse(Schema::hasTable('sector_user'));
        $this->assertFalse(Schema::hasTable('sectors'));
        $audit->down();
        $invites->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('company_user'));
        $this->assertFalse(Schema::hasTable('companies'));
        $this->assertTrue(User::query()->where('email', 'ana@acme.com')->exists());

        $migration->up();
        $invites->up();
        $audit->up();
        $sectors->up();
        $monitors->up();
        $owned->up();
        $notices->up();
        $channels->up();
        $this->assertTrue(Schema::hasColumns('notification_channels', ['company_id', 'sector_id']));
        $this->assertTrue(Schema::hasColumns('alert_profiles', ['company_id', 'sector_id']));
        $this->assertTrue(Schema::hasColumns('owned_domains', ['company_id', 'sector_id']));
        $this->assertTrue(Schema::hasColumns('sector_user', ['sector_id', 'user_id', 'is_manager']));
        $this->assertTrue(Schema::hasColumns('companies', ['name', 'domain_token', 'limit_accounts', 'limit_radar_seeds', 'require_2fa', 'enterprise_enabled_at', 'suspended_at']));
        $this->assertTrue(Schema::hasColumns('company_user', ['company_id', 'user_id', 'role', 'invited_at', 'joined_at', 'deactivated_at']));
        $this->companies()->create('Acme de novo', 'Bia', 'bia@acme.com');
        $this->assertSame(1, Company::query()->count());
    }
}
