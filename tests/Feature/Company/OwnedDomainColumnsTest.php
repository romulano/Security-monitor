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
 * E3a0 (DESENHO-5.3 §2.2): a posse do domínio pode ser da empresa (`owned_domains.company_id`/`sector_id`), com as
 * travas do banco e o único por conta ou por empresa. Só a estrutura; ninguém lê nem grava as colunas ainda.
 */
class OwnedDomainColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_12_14_000100_add_company_and_sector_to_owned_domains.php';

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
        DB::table('company_user')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    /** Uma posse como o código grava (DB::table, sem o modelo): o dono e o que mais for pedido. */
    private function insert(User $owner, string $domain, array $values = []): int
    {
        return DB::table('owned_domains')->insertGetId($values + [
            'user_id' => $owner->id, 'domain' => $domain, 'source' => 'radar', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_columns_are_optional_and_the_personal_account_does_not_change(): void
    {
        $this->assertTrue(Schema::hasColumns('owned_domains', ['company_id', 'sector_id', 'subdomains_checked_at', 'subdomains_error']));

        // Conta pessoal, como o código no ar grava: sem empresa nem setor, e o mesmo nome continua único por conta.
        $bia = User::factory()->create();
        $id = $this->insert($bia, 'bia.com.br');
        $this->assertSame([null, null], array_values((array) DB::table('owned_domains')->where('id', $id)->first(['company_id', 'sector_id'])));
        $this->assertRefused(fn () => $this->insert($bia, 'bia.com.br'), 'o mesmo nome duas vezes na conta pessoal');

        // Outra conta pessoal pode pedir o mesmo nome (quem tem o TXT prova), como hoje.
        $this->insert(User::factory()->create(), 'bia.com.br');

        // Domínio da empresa, com e sem setor.
        $ana = $this->employee($this->acme);
        $this->insert($ana, 'acme.com.br', ['company_id' => $this->acme->id]);
        $this->insert($ana, 'loja.acme.com.br', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
        $this->assertSame(2, DB::table('owned_domains')->where('company_id', $this->acme->id)->count());
    }

    public function test_one_name_per_company_whoever_registers_it(): void
    {
        $ana = $this->employee($this->acme);
        $caio = $this->employee($this->acme);
        $this->insert($ana, 'acme.com.br', ['company_id' => $this->acme->id]);

        // Outra conta da mesma empresa não cadastra o mesmo nome de novo, com ou sem setor.
        $this->assertRefused(fn () => $this->insert($caio, 'acme.com.br', ['company_id' => $this->acme->id]), 'o mesmo nome duas vezes na empresa');
        $this->assertRefused(fn () => $this->insert($caio, 'acme.com.br', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]), 'o mesmo nome em outro setor');

        // Outra empresa e uma conta pessoal podem pedir o mesmo nome (a prova é o TXT de cada uma).
        $this->insert($this->employee($this->beta), 'acme.com.br', ['company_id' => $this->beta->id]);
        $this->insert(User::factory()->create(), 'acme.com.br');
        $this->assertSame(3, DB::table('owned_domains')->where('domain', 'acme.com.br')->count());
    }

    public function test_the_database_refuses_mixing_companies_and_sectors(): void
    {
        $ana = $this->employee($this->acme);

        // Setor sem empresa (check) e setor de outra empresa (trigger).
        $this->assertRefused(fn () => $this->insert($ana, 'a.com.br', ['sector_id' => $this->ti->id]), 'setor sem empresa');
        $this->assertRefused(fn () => $this->insert($ana, 'b.com.br', ['company_id' => $this->acme->id, 'sector_id' => $this->vendas->id]), 'setor de outra empresa');

        // Conta pessoal ou de outra empresa não cadastra domínio da Acme.
        $this->assertRefused(fn () => $this->insert(User::factory()->create(), 'c.com.br', ['company_id' => $this->acme->id]), 'conta pessoal na Acme');
        $this->assertRefused(fn () => $this->insert($this->employee($this->beta), 'd.com.br', ['company_id' => $this->acme->id]), 'conta da Beta na Acme');

        // O domínio da Acme não muda de empresa, não volta a pessoal, não troca para setor ou dono de outra empresa.
        $id = $this->insert($ana, 'acme.com.br', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
        $update = fn (array $values) => fn () => DB::table('owned_domains')->where('id', $id)->update($values);
        $this->assertRefused($update(['company_id' => $this->beta->id, 'sector_id' => $this->vendas->id]), 'Acme → Beta');
        $this->assertRefused($update(['company_id' => null, 'sector_id' => null]), 'Acme → pessoal');
        $this->assertRefused($update(['sector_id' => $this->vendas->id]), 'setor da Beta');
        $this->assertRefused($update(['user_id' => $this->employee($this->beta)->id]), 'dono da Beta');
        $this->assertSame([$this->acme->id, $this->ti->id], array_values((array) DB::table('owned_domains')->where('id', $id)->first(['company_id', 'sector_id'])));

        // Passar para outra conta da mesma empresa (a poda, P7) pode.
        $admin = (int) DB::table('company_user')->where('company_id', $this->acme->id)->where('role', 'admin')->value('user_id');
        DB::table('owned_domains')->where('id', $id)->update(['user_id' => $admin]);
        $this->assertSame($admin, (int) DB::table('owned_domains')->where('id', $id)->value('user_id'));
    }

    public function test_deleting_the_sector_leaves_the_domain_without_sector_and_the_company_cannot_go_with_domains(): void
    {
        $id = $this->insert($this->employee($this->acme), 'acme.com.br', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);

        DB::table('sectors')->where('id', $this->ti->id)->delete();
        $this->assertSame([$this->acme->id, null], array_values((array) DB::table('owned_domains')->where('id', $id)->first(['company_id', 'sector_id'])));

        // A empresa com domínios não some por baixo (a exclusão da empresa é da E6b).
        $this->assertRefused(fn () => DB::table('companies')->where('id', $this->acme->id)->delete(), 'apagar a empresa');
    }

    public function test_the_migration_is_idempotent_and_reversible(): void
    {
        $migration = require database_path(self::MIGRATION);
        $bia = User::factory()->create();
        $this->insert($bia, 'bia.com.br');

        DB::transaction(function () use ($migration, $bia) {
            $migration->up();

            $migration->down();
            $migration->down();

            $this->assertFalse(Schema::hasColumn('owned_domains', 'company_id'));
            $this->assertFalse(Schema::hasColumn('owned_domains', 'subdomains_checked_at'));
            // O único de antes volta.
            $this->assertRefused(fn () => $this->insert($bia, 'bia.com.br'), 'único recriado');

            $migration->up();
            $migration->up();
        });

        $this->assertTrue(Schema::hasColumns('owned_domains', ['company_id', 'sector_id']));
        $this->assertSame(1, DB::table('owned_domains')->where('user_id', $bia->id)->count(), 'a linha pessoal atravessa o down e o up');
        $this->assertRefused(fn () => $this->insert($this->employee($this->acme), 'x.com.br', ['company_id' => $this->acme->id, 'sector_id' => $this->vendas->id]), 'trigger de volta');
    }

    // O down com o mesmo nome numa linha pessoal e numa da empresa da mesma conta falha inteiro: nada fica pela metade.
    public function test_the_down_fails_whole_when_the_old_unique_would_break(): void
    {
        $ana = $this->employee($this->acme);
        $this->insert($ana, 'acme.com.br');
        $this->insert($ana, 'acme.com.br', ['company_id' => $this->acme->id]);
        $migration = require database_path(self::MIGRATION);

        $this->assertRefused(fn () => $migration->down(), 'down com o nome repetido na conta');

        $this->assertTrue(Schema::hasColumns('owned_domains', ['company_id', 'sector_id']));
        $this->assertSame(1, DB::table('pg_indexes')->where('indexname', 'owned_domains_one_per_company')->count());
        $this->assertSame(2, DB::table('owned_domains')->where('user_id', $ana->id)->count());
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
