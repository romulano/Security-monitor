<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Red Team da E2b0 (empresa e setor nas raízes: alert_profiles, lookalike_watches, github_monitors). */
class RedTeamE2b0Test extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['alert_profiles', 'lookalike_watches', 'github_monitors'];

    private Company $acme;

    private Company $beta;

    private Sector $ti;

    private Sector $vendas;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->acme = app(Companies::class)->create('Acme', 'Ana', 'ana@acme.com');
        $this->beta = app(Companies::class)->create('Beta', 'Paulo', 'paulo@beta.com');
        $this->ti = Sector::query()->forceCreate(['company_id' => $this->acme->id, 'name' => 'TI']);
        $this->vendas = Sector::query()->forceCreate(['company_id' => $this->beta->id, 'name' => 'Vendas']);
    }

    private function employee(Company $company): User
    {
        $user = User::factory()->create();
        $company->members()->forceCreate(['user_id' => $user->id, 'role' => CompanyRole::Member, 'joined_at' => now()]);

        return $user;
    }

    private function insert(string $table, User $owner, array $values = []): int
    {
        $base = ['user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()];

        if ($table === 'lookalike_watches') {
            $base['domain_id'] = DB::table('monitored_domains')->insertGetId([
                'user_id' => $owner->id, 'domain' => uniqid().'.com.br', 'verify_token' => str_repeat('a', 32), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return DB::table($table)->insertGetId($values + $base);
    }

    private function refused(callable $write): bool
    {
        try {
            DB::transaction(fn () => $write());
        } catch (QueryException) {
            return true;
        }

        return false;
    }

    // N1a — conta pessoal com company_id: o item dela entra na Acme (a E2b vai mostrá-lo à empresa toda).
    public function test_rt_n1a_item_de_conta_pessoal_nao_entra_na_empresa(): void
    {
        foreach (self::TABLES as $table) {
            $personal = User::factory()->create();
            $this->assertTrue($this->refused(fn () => $this->insert($table, $personal, ['company_id' => $this->acme->id])), "{$table}: pessoal na Acme ao criar");

            $id = $this->insert($table, User::factory()->create());
            $this->assertTrue($this->refused(fn () => DB::table($table)->where('id', $id)->update(['company_id' => $this->acme->id, 'sector_id' => $this->ti->id])), "{$table}: pessoal na Acme ao mudar");
        }
    }

    // N1b — trocar company_id e sector_id juntos leva o item da Acme (criado por conta da Acme) para a Beta.
    public function test_rt_n1b_item_nao_muda_de_empresa_com_as_duas_colunas_juntas(): void
    {
        foreach (self::TABLES as $table) {
            $id = $this->insert($table, $this->employee($this->acme), ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);

            $this->assertTrue($this->refused(fn () => DB::table($table)->where('id', $id)->update(['company_id' => $this->beta->id, 'sector_id' => $this->vendas->id])), "{$table}: Acme → Beta");
            $this->assertTrue($this->refused(fn () => DB::table($table)->where('id', $id)->update(['company_id' => null, 'sector_id' => null])), "{$table}: Acme → pessoal");
        }
    }

    // N1c — trocar só o user_id (o trigger não olha a coluna): item da Acme com dono da Beta.
    public function test_rt_n1c_o_dono_nao_vira_conta_de_outra_empresa(): void
    {
        $paulo = $this->employee($this->beta);

        foreach (self::TABLES as $table) {
            $id = $this->insert($table, $this->employee($this->acme), ['company_id' => $this->acme->id]);
            $this->assertTrue($this->refused(fn () => DB::table($table)->where('id', $id)->update(['user_id' => $paulo->id])), "{$table}: dono da Beta");
        }
    }

    // N1d — o único parcial do GitHub: a conta pessoal com company_id forjado teria vários monitores.
    public function test_rt_n1d_conta_pessoal_nao_fura_o_unico_do_github(): void
    {
        $personal = User::factory()->create();
        $this->insert('github_monitors', $personal);
        $this->refused(fn () => $this->insert('github_monitors', $personal, ['company_id' => $this->acme->id]));

        $this->assertSame(1, DB::table('github_monitors')->where('user_id', $personal->id)->count(), 'Conta pessoal com dois monitores.');
    }

    // Evidência — update em lote passa pelo trigger linha a linha; o setor apagado deixa o item sem setor.
    public function test_evidencia_update_em_lote_e_setor_apagado(): void
    {
        $ana = $this->employee($this->acme);
        foreach (self::TABLES as $table) {
            $this->insert($table, $ana, ['company_id' => $this->acme->id]);
            $this->insert($table, $ana, ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
            $this->assertTrue($this->refused(fn () => DB::table($table)->update(['sector_id' => $this->vendas->id])), $table);
        }

        DB::table('sectors')->where('id', $this->ti->id)->delete();
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->whereNotNull('sector_id')->count(), $table);
        }
    }

    // Evidência — o down com dois monitores de uma conta de empresa falha inteiro (a migration roda numa transação):
    // nada fica pela metade. O rollback só volta depois de tirar os monitores a mais (documentado na migration).
    public function test_evidencia_down_com_varios_monitores_da_empresa_falha_inteiro(): void
    {
        $ana = $this->employee($this->acme);
        $this->insert('github_monitors', $ana, ['company_id' => $this->acme->id]);
        $this->insert('github_monitors', $ana, ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
        $migration = require database_path('migrations/2026_12_12_000100_add_company_and_sector_to_monitors.php');

        $this->assertTrue($this->refused(fn () => $migration->down()));

        $this->assertTrue(Schema::hasColumns('github_monitors', ['company_id', 'sector_id']));
        $this->assertSame(1, DB::table('pg_indexes')->where('indexname', 'github_monitors_one_per_account')->count());
        $this->assertSame(2, DB::table('github_monitors')->where('user_id', $ana->id)->count());
    }
}
