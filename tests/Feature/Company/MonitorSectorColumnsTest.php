<?php

namespace Tests\Feature\Company;

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

/**
 * E2b0 (DESENHO-5.2 §3): `company_id` e `sector_id` no alerta de Notícias, no Radar e no GitHub. Só a estrutura e as
 * travas do banco; ninguém lê nem grava as colunas ainda.
 */
class MonitorSectorColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_12_12_000100_add_company_and_sector_to_monitors.php';

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

    private function employee(int $companyId): User
    {
        $user = User::factory()->create();
        DB::table('company_user')->insert(['company_id' => $companyId, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    /** Uma linha na tabela, com o dono e o que mais for pedido. */
    private function insert(string $table, array $values = [], ?User $owner = null): int
    {
        // O item de empresa é de uma conta dela (o trigger amarra a empresa do item à de quem criou).
        $owner ??= isset($values['company_id']) ? $this->employee($values['company_id']) : User::factory()->create();
        $base = ['user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()];

        if ($table === 'lookalike_watches') {
            $base['domain_id'] = DB::table('monitored_domains')->insertGetId([
                'user_id' => $owner->id, 'domain' => uniqid().'.com.br', 'verify_token' => str_repeat('a', 32), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return DB::table($table)->insertGetId($values + $base);
    }

    public function test_the_columns_are_optional_and_the_personal_account_does_not_change(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['company_id', 'sector_id']), $table);

            // Conta pessoal, como o código no ar grava: sem empresa nem setor.
            $id = $this->insert($table);
            $this->assertSame([null, null], array_values((array) DB::table($table)->where('id', $id)->first(['company_id', 'sector_id'])));

            // Item da empresa, com e sem setor.
            $this->insert($table, ['company_id' => $this->acme->id]);
            $this->insert($table, ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
        }
    }

    public function test_a_sector_needs_its_company_on_every_table(): void
    {
        foreach (self::TABLES as $table) {
            // Setor sem empresa: o check recusa.
            $this->assertRefused(fn () => $this->insert($table, ['sector_id' => $this->ti->id]), $table.' sem empresa');
            // Setor de outra empresa: o trigger recusa, ao criar.
            $this->assertRefused(fn () => $this->insert($table, ['company_id' => $this->acme->id, 'sector_id' => $this->vendas->id]), $table.' ao criar');

            // E ao mudar a empresa ou o setor de um item que já existe.
            $id = $this->insert($table, ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
            $this->assertRefused(fn () => DB::table($table)->where('id', $id)->update(['company_id' => $this->beta->id]), $table.' ao trocar a empresa');
            $this->assertRefused(fn () => DB::table($table)->where('id', $id)->update(['sector_id' => $this->vendas->id]), $table.' ao trocar o setor');
            $this->assertSame([$this->acme->id, $this->ti->id], array_values((array) DB::table($table)->where('id', $id)->first(['company_id', 'sector_id'])));
        }
    }

    public function test_deleting_the_sector_leaves_the_item_without_sector_and_the_company_cannot_go_with_items(): void
    {
        $ids = collect(self::TABLES)->mapWithKeys(fn ($table) => [$table => $this->insert($table, ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id])]);

        DB::table('sectors')->where('id', $this->ti->id)->delete();

        foreach ($ids as $table => $id) {
            $this->assertSame([$this->acme->id, null], array_values((array) DB::table($table)->where('id', $id)->first(['company_id', 'sector_id'])), $table);
        }

        // A empresa com itens não some por baixo (a exclusão da empresa é da E6b).
        $this->assertRefused(fn () => DB::table('companies')->where('id', $this->acme->id)->delete(), 'apagar a empresa');
    }

    public function test_the_personal_account_keeps_one_github_monitor_and_the_company_may_have_more(): void
    {
        $owner = User::factory()->create();
        $this->insert('github_monitors', [], $owner);
        $this->assertRefused(fn () => $this->insert('github_monitors', [], $owner), 'segundo monitor da conta pessoal');

        $manager = $this->employee($this->acme->id);
        $this->insert('github_monitors', ['company_id' => $this->acme->id], $manager);
        $this->insert('github_monitors', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id], $manager);
        $this->assertSame(2, DB::table('github_monitors')->where('user_id', $manager->id)->count());
    }

    public function test_the_migration_is_idempotent_and_reversible(): void
    {
        $migration = require database_path(self::MIGRATION);

        DB::transaction(function () use ($migration) {
            $migration->up();

            $migration->down();
            $migration->down();

            foreach (self::TABLES as $table) {
                $this->assertFalse(Schema::hasColumn($table, 'company_id'), $table);
                $this->assertFalse(Schema::hasColumn($table, 'sector_id'), $table);
            }

            // O único de antes volta.
            $owner = User::factory()->create();
            $this->insert('github_monitors', [], $owner);
            $this->assertRefused(fn () => $this->insert('github_monitors', [], $owner), 'único recriado');

            $migration->up();
            $migration->up();
        });

        $this->assertTrue(Schema::hasColumns('github_monitors', ['company_id', 'sector_id']));
        $this->assertRefused(fn () => $this->insert('alert_profiles', ['company_id' => $this->acme->id, 'sector_id' => $this->vendas->id]), 'trigger de volta');
    }

    /** A operação falha no banco, sem estragar a transação do teste (savepoint). */
    private function assertRefused(callable $operation, string $what): void
    {
        try {
            DB::transaction($operation);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("O banco aceitou: {$what}");
    }
}
