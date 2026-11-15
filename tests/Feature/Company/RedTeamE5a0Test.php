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
use Tests\TestCase;

/**
 * Red Team da E5a0 (DESENHO-5.4 §8.3): contornar o trigger dos canais da empresa e o único parcial.
 * `rt_*` = achado (falha antes da correção); `evidencia_*` = ataque que o banco já barra.
 */
class RedTeamE5a0Test extends TestCase
{
    use RefreshDatabase;

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

    private function insert(User $owner, string $type, array $values = []): int
    {
        return DB::table('notification_channels')->insertGetId($values + [
            'user_id' => $owner->id, 'type' => $type, 'method' => match ($type) {
                'telegram' => 'group', 'discord' => 'channel', 'webhook' => 'json', default => 'webhook',
            }, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A1: o P1 só olha o `method`, mas quem decide para onde a mensagem vai é o `telegram_chat_id`
     * (TelegramClient::post manda para ele seja qual for o method). No Telegram, conversa privada é o ID
     * positivo (o da pessoa); grupo e supergrupo são negativos. E o `update of` do trigger não inclui a coluna.
     */
    public function test_rt_a1_company_telegram_never_points_to_a_private_chat(): void
    {
        $ana = $this->employee($this->acme);

        $this->assertRefused(fn () => $this->insert($ana, 'telegram', ['company_id' => $this->acme->id, 'telegram_chat_id' => '777000123']), 'grupo da empresa com ID de conversa privada');
        $this->assertRefused(fn () => $this->insert($ana, 'telegram', ['company_id' => $this->acme->id, 'method' => 'Private', 'telegram_chat_id' => '777000123']), 'method com outra grafia');

        // Conectado num grupo de verdade, depois o destino troca para a conversa da pessoa (só o chat_id muda).
        $id = $this->insert($ana, 'telegram', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id, 'telegram_chat_id' => '-1001234567890']);
        $this->assertRefused(fn () => DB::table('notification_channels')->where('id', $id)->update(['telegram_chat_id' => '777000123']), 'grupo → conversa privada pelo chat_id');
        $this->assertSame('-1001234567890', DB::table('notification_channels')->where('id', $id)->value('telegram_chat_id'));
    }

    // O que o A1 não pode quebrar: a migração de grupo para supergrupo, o canal pendente e o Telegram privado pessoal.
    public function test_evidencia_a1_supergroup_migration_pending_and_personal_private_still_work(): void
    {
        $ana = $this->employee($this->acme);
        $id = $this->insert($ana, 'telegram', ['company_id' => $this->acme->id, 'telegram_chat_id' => '-4567']);
        DB::table('notification_channels')->where('telegram_chat_id', '-4567')->update(['telegram_chat_id' => '-1004567']);
        $this->assertSame('-1004567', DB::table('notification_channels')->where('id', $id)->value('telegram_chat_id'));

        $this->insert($ana, 'telegram', ['company_id' => $this->acme->id, 'telegram_chat_id' => null]);
        $this->insert(User::factory()->create(), 'telegram', ['method' => 'private', 'telegram_chat_id' => '777000123']);
        $this->insert($ana, 'telegram', ['method' => 'private', 'telegram_chat_id' => '777000124']);
        $this->assertSame(2, DB::table('notification_channels')->where('company_id', $this->acme->id)->count());
    }

    // Update em lote: o trigger é por linha, então uma linha ruim derruba o comando inteiro.
    public function test_evidencia_batch_updates_are_checked_row_by_row(): void
    {
        $ana = $this->employee($this->acme);
        $bia = User::factory()->create();
        $a = $this->insert($ana, 'slack', ['company_id' => $this->acme->id]);
        $b = $this->insert($ana, 'discord', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);
        $personal = $this->insert($bia, 'slack');

        $this->assertRefused(fn () => DB::table('notification_channels')->whereIn('id', [$a, $b])->update(['company_id' => $this->beta->id]), 'lote para a Beta');
        $this->assertRefused(fn () => DB::table('notification_channels')->whereIn('id', [$a, $b])->update(['user_id' => $bia->id]), 'lote para conta pessoal');
        $this->assertRefused(fn () => DB::table('notification_channels')->whereIn('id', [$a, $b])->update(['sector_id' => $this->vendas->id]), 'lote para setor da Beta');
        $this->assertRefused(fn () => DB::table('notification_channels')->whereIn('id', [$a, $b])->update(['type' => 'discord', 'method' => 'dm']), 'lote para DM');
        // Conta pessoal com empresa forjada (sozinha ou no meio de um lote da própria empresa).
        $this->assertRefused(fn () => DB::table('notification_channels')->where('id', $personal)->update(['company_id' => $this->acme->id]), 'pessoal forjando a Acme');
        $this->assertRefused(fn () => DB::table('notification_channels')->whereIn('id', [$a, $personal])->update(['sector_id' => $this->ti->id]), 'pessoal no lote do setor');

        $this->assertSame(2, DB::table('notification_channels')->where('company_id', $this->acme->id)->count());
        $this->assertNull(DB::table('notification_channels')->where('id', $personal)->value('company_id'));
    }

    // O único parcial: a empresa forjada não abre um segundo Slack pessoal, e um canal de empresa não volta a pessoal.
    public function test_evidencia_the_partial_unique_cannot_be_dodged(): void
    {
        $ana = $this->employee($this->acme);
        $bia = User::factory()->create();
        $this->insert($bia, 'slack');
        $this->assertRefused(fn () => $this->insert($bia, 'slack', ['company_id' => $this->acme->id]), 'pessoal com empresa forjada');

        $this->insert($ana, 'slack');
        $company = $this->insert($ana, 'slack', ['company_id' => $this->acme->id]);
        $this->assertRefused(fn () => DB::table('notification_channels')->where('id', $company)->update(['company_id' => null]), 'empresa → segundo pessoal');
        $this->assertSame(1, DB::table('notification_channels')->where('user_id', $bia->id)->count());
        $this->assertSame(1, DB::table('notification_channels')->where('user_id', $ana->id)->whereNull('company_id')->where('type', 'slack')->count());
    }

    // Trocar o tipo depois de conectar: Slack da empresa virando Telegram privado ou DM do Discord.
    public function test_evidencia_changing_the_app_after_connecting_is_checked(): void
    {
        $ana = $this->employee($this->acme);
        $id = $this->insert($ana, 'slack', ['company_id' => $this->acme->id]);

        $this->assertRefused(fn () => DB::table('notification_channels')->where('id', $id)->update(['type' => 'telegram', 'method' => 'private']), 'Slack → Telegram privado');
        $this->assertRefused(fn () => DB::table('notification_channels')->where('id', $id)->update(['type' => 'discord', 'method' => 'dm']), 'Slack → DM');
        $this->assertSame('slack', DB::table('notification_channels')->where('id', $id)->value('type'));
    }

    // O nullOnDelete do setor passa pelo trigger (é um update): o canal vira geral e continua da empresa.
    public function test_evidencia_sector_delete_goes_through_the_trigger(): void
    {
        $ana = $this->employee($this->acme);
        $id = $this->insert($ana, 'telegram', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id, 'telegram_chat_id' => '-55']);
        DB::table('sectors')->where('id', $this->ti->id)->delete();
        $this->assertSame([$this->acme->id, null], array_values((array) DB::table('notification_channels')->where('id', $id)->first(['company_id', 'sector_id'])));
    }

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
