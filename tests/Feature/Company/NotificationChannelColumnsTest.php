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
 * E5a0 (DESENHO-5.4 §2.2): o canal de aviso pode ser da empresa (`notification_channels.company_id`/`sector_id`), com
 * as travas do banco, o único "um app por conta" só na conta pessoal e o `sectors.copy_to_general`. Só a estrutura;
 * ninguém lê nem grava as colunas ainda.
 */
class NotificationChannelColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_12_18_000100_add_company_and_sector_to_notification_channels.php';

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

    /** Um canal como o código grava (DB::table, sem o modelo): o dono, o app e o que mais for pedido. */
    private function insert(User $owner, string $type, array $values = []): int
    {
        return DB::table('notification_channels')->insertGetId($values + [
            'user_id' => $owner->id, 'type' => $type, 'method' => match ($type) {
                'telegram' => 'group', 'discord' => 'channel', 'webhook' => 'json', default => 'webhook',
            }, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_columns_are_optional_and_the_personal_account_does_not_change(): void
    {
        $this->assertTrue(Schema::hasColumns('notification_channels', ['company_id', 'sector_id']));
        $this->assertTrue(Schema::hasColumn('sectors', 'copy_to_general'));
        $this->assertFalse((bool) DB::table('sectors')->where('id', $this->ti->id)->value('copy_to_general'), 'D5: copiar para os gerais começa desligado');

        // Conta pessoal, como o código no ar grava: sem empresa nem setor, um de cada app e vários webhooks.
        $bia = User::factory()->create();
        $id = $this->insert($bia, 'slack');
        $this->assertSame([null, null], array_values((array) DB::table('notification_channels')->where('id', $id)->first(['company_id', 'sector_id'])));
        $this->assertRefused(fn () => $this->insert($bia, 'slack'), 'dois Slacks na conta pessoal');
        $this->insert($bia, 'webhook');
        $this->insert($bia, 'webhook');
        $this->insert($bia, 'telegram', ['method' => 'private']);
        $this->insert($bia, 'discord', ['method' => 'dm']);
        $this->assertSame(5, DB::table('notification_channels')->where('user_id', $bia->id)->count());
    }

    public function test_the_company_can_have_several_channels_of_the_same_app_in_the_same_scope(): void
    {
        $ana = $this->employee($this->acme);

        // Gerais e de setor, vários do mesmo app (P4): o teto é a contagem (E5a1), não o único.
        $this->insert($ana, 'slack', ['company_id' => $this->acme->id]);
        $this->insert($ana, 'slack', ['company_id' => $this->acme->id]);
        $this->insert($ana, 'slack', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
        $this->insert($ana, 'slack', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);

        // E o Slack pessoal da mesma pessoa não conflita com os da empresa: o único é só da conta pessoal.
        $this->insert($ana, 'slack');
        $this->assertRefused(fn () => $this->insert($ana, 'slack'), 'dois Slacks pessoais');
        $this->assertSame(4, DB::table('notification_channels')->where('company_id', $this->acme->id)->count());
    }

    public function test_the_database_refuses_mixing_companies_and_sectors(): void
    {
        $ana = $this->employee($this->acme);

        // Setor sem empresa (check) e setor de outra empresa (trigger).
        $this->assertRefused(fn () => $this->insert($ana, 'slack', ['sector_id' => $this->ti->id]), 'setor sem empresa');
        $this->assertRefused(fn () => $this->insert($ana, 'slack', ['company_id' => $this->acme->id, 'sector_id' => $this->vendas->id]), 'setor de outra empresa');

        // Conta pessoal ou de outra empresa não conecta canal da Acme.
        $this->assertRefused(fn () => $this->insert(User::factory()->create(), 'slack', ['company_id' => $this->acme->id]), 'conta pessoal na Acme');
        $this->assertRefused(fn () => $this->insert($this->employee($this->beta), 'slack', ['company_id' => $this->acme->id]), 'conta da Beta na Acme');

        // O canal da Acme não muda de empresa, não volta a pessoal, não troca para setor ou dono de outra empresa.
        $id = $this->insert($ana, 'discord', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
        $update = fn (array $values) => fn () => DB::table('notification_channels')->where('id', $id)->update($values);
        $this->assertRefused($update(['company_id' => $this->beta->id, 'sector_id' => $this->vendas->id]), 'Acme → Beta');
        $this->assertRefused($update(['company_id' => null, 'sector_id' => null]), 'Acme → pessoal');
        $this->assertRefused($update(['sector_id' => $this->vendas->id]), 'setor da Beta');
        $this->assertRefused($update(['user_id' => $this->employee($this->beta)->id]), 'dono da Beta');
        $this->assertSame([$this->acme->id, $this->ti->id], array_values((array) DB::table('notification_channels')->where('id', $id)->first(['company_id', 'sector_id'])));

        // Passar para outra conta da mesma empresa (a poda, P7) e trocar de setor dentro da empresa pode.
        $admin = (int) DB::table('company_user')->where('company_id', $this->acme->id)->where('role', 'admin')->value('user_id');
        DB::table('notification_channels')->where('id', $id)->update(['user_id' => $admin, 'sector_id' => null]);
        $this->assertSame([$admin, null], [(int) DB::table('notification_channels')->where('id', $id)->value('user_id'), DB::table('notification_channels')->where('id', $id)->value('sector_id')]);
    }

    // R11 / P1 do 5.4: o canal da empresa não é a conversa de uma pessoa, nem ao conectar nem ao reconectar.
    public function test_a_company_channel_is_never_a_private_conversation(): void
    {
        $ana = $this->employee($this->acme);

        $this->assertRefused(fn () => $this->insert($ana, 'telegram', ['company_id' => $this->acme->id, 'method' => 'private']), 'Telegram privado da empresa');
        $this->assertRefused(fn () => $this->insert($ana, 'discord', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id, 'method' => 'dm']), 'DM do Discord do setor');

        $telegram = $this->insert($ana, 'telegram', ['company_id' => $this->acme->id]);
        $discord = $this->insert($ana, 'discord', ['company_id' => $this->acme->id]);
        $this->assertRefused(fn () => DB::table('notification_channels')->where('id', $telegram)->update(['method' => 'private']), 'grupo → privado');
        $this->assertRefused(fn () => DB::table('notification_channels')->where('id', $discord)->update(['method' => 'dm']), 'canal → DM');

        // A conversa privada pessoal não passa para a empresa (o carimbo da E5a1 desconecta, não carimba).
        $private = $this->insert($ana, 'telegram', ['method' => 'private']);
        $this->assertRefused(fn () => DB::table('notification_channels')->where('id', $private)->update(['company_id' => $this->acme->id]), 'privado pessoal → empresa');

        // O Discord pelo webhook e o Slack valem.
        $this->insert($ana, 'discord', ['company_id' => $this->acme->id, 'method' => 'webhook']);
        $this->assertSame(3, DB::table('notification_channels')->where('company_id', $this->acme->id)->count());
    }

    public function test_deleting_the_sector_makes_the_channel_general_and_the_company_cannot_go_with_channels(): void
    {
        $ana = $this->employee($this->acme);
        $general = $this->insert($ana, 'slack', ['company_id' => $this->acme->id]);
        $sector = $this->insert($ana, 'slack', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);

        // Dois Slacks gerais depois de apagar o setor: sem único por app na empresa, o apagar não estoura (P4/P5).
        DB::table('sectors')->where('id', $this->ti->id)->delete();
        $this->assertSame([$this->acme->id, null], array_values((array) DB::table('notification_channels')->where('id', $sector)->first(['company_id', 'sector_id'])));
        $this->assertSame(2, DB::table('notification_channels')->whereIn('id', [$general, $sector])->whereNull('sector_id')->count());

        // A empresa com canais não some por baixo (a exclusão da empresa é da E6b).
        DB::table('sectors')->where('company_id', $this->acme->id)->delete();
        $this->assertRefused(fn () => DB::table('companies')->where('id', $this->acme->id)->delete(), 'apagar a empresa');
    }

    public function test_the_migration_is_idempotent_and_reversible(): void
    {
        $migration = require database_path(self::MIGRATION);
        $bia = User::factory()->create();
        $this->insert($bia, 'slack');

        DB::transaction(function () use ($migration, $bia) {
            $migration->up();

            $migration->down();
            $migration->down();

            $this->assertFalse(Schema::hasColumn('notification_channels', 'company_id'));
            $this->assertFalse(Schema::hasColumn('sectors', 'copy_to_general'));
            // O único de antes volta.
            $this->assertRefused(fn () => $this->insert($bia, 'slack'), 'único recriado');

            $migration->up();
            $migration->up();
        });

        $this->assertTrue(Schema::hasColumns('notification_channels', ['company_id', 'sector_id']));
        $this->assertSame(1, DB::table('notification_channels')->where('user_id', $bia->id)->count(), 'o canal pessoal atravessa o down e o up');
        $this->assertRefused(fn () => $this->insert($bia, 'slack'), 'único parcial de volta');
        $this->assertRefused(fn () => $this->insert($this->employee($this->acme), 'slack', ['company_id' => $this->acme->id, 'sector_id' => $this->vendas->id]), 'trigger de volta');
    }

    // Com canal de empresa, o down falha inteiro: nada fica pela metade, e o canal não vira pessoal calado.
    public function test_the_down_fails_whole_when_there_is_a_company_channel(): void
    {
        $ana = $this->employee($this->acme);
        $this->insert($ana, 'slack', ['company_id' => $this->acme->id]);
        $migration = require database_path(self::MIGRATION);

        $this->assertRefused(fn () => $migration->down(), 'down com canal de empresa');

        $this->assertTrue(Schema::hasColumns('notification_channels', ['company_id', 'sector_id']));
        $this->assertTrue(Schema::hasColumn('sectors', 'copy_to_general'));
        $this->assertSame(1, DB::table('notification_channels')->where('company_id', $this->acme->id)->count());
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
