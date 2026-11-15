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
 * Red Team da E3a0 (pelo SQL). `rt_*`: passava no 170249a e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * - W1: a linha da empresa aceita tokens que não são o da empresa, e a posse pessoal vira da empresa por um update
 *   (company_id nulo → empresa) levando o proof_token e os tokens legados da conta. Fere a invariante do §2.3 (a
 *   empresa prova só com o token dela). Passar à empresa limpando os tokens (o carimbo da E3a2) continua podendo.
 * - W2: o `down` sem nome repetido transforma, calado, cada posse da empresa em posse pessoal de quem cadastrou
 *   (com o token da empresa no proof_token: entra no conjunto de tokens da conta).
 */
class RedTeamE3a0Test extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_12_14_000100_add_company_and_sector_to_owned_domains.php';

    private Company $acme;

    private Sector $ti;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->acme = app(Companies::class)->create('Acme', 'Ana', 'ana@acme.com');
        $this->ti = Sector::query()->forceCreate(['company_id' => $this->acme->id, 'name' => 'TI']);
    }

    private function employee(): User
    {
        $user = User::factory()->create();
        DB::table('company_user')->insert(['company_id' => $this->acme->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function insert(User $owner, string $domain, array $values = []): int
    {
        return DB::table('owned_domains')->insertGetId($values + [
            'user_id' => $owner->id, 'domain' => $domain, 'source' => 'radar', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function companyToken(): string
    {
        return (string) DB::table('companies')->where('id', $this->acme->id)->value('domain_token');
    }

    private function refused(callable $operation): bool
    {
        try {
            DB::transaction($operation);
        } catch (QueryException) {
            return true;
        }

        return false;
    }

    public function test_rt_w1_a_personal_proof_never_reaches_the_company_with_the_account_tokens(): void
    {
        // A conta comprovou bia.com.br como pessoal (o token dela), antes ou depois de entrar na empresa.
        $bia = $this->employee();
        $id = $this->insert($bia, 'bia.com.br', ['proof_token' => 'pessoal-da-bia', 'tokens' => json_encode(['legado-da-bia']), 'verified_at' => now()]);

        // No 170249a, o update passava: a empresa ganhava uma posse com os tokens da conta.
        $this->assertTrue($this->refused(fn () => DB::table('owned_domains')->where('id', $id)->update(['company_id' => $this->acme->id])), 'pessoal → empresa com tokens');
        $this->assertNull(DB::table('owned_domains')->where('id', $id)->value('company_id'));

        // Passar à empresa sem os tokens da conta (o carimbo da E3a2) pode.
        DB::table('owned_domains')->where('id', $id)->update(['company_id' => $this->acme->id, 'tokens' => '[]', 'proof_token' => null]);
        $this->assertSame($this->acme->id, (int) DB::table('owned_domains')->where('id', $id)->value('company_id'));
    }

    public function test_rt_w1_a_company_row_only_carries_the_company_token(): void
    {
        $ana = $this->employee();

        // Linha nova da empresa com token de fora (o da conta, um legado): recusada.
        $this->assertTrue($this->refused(fn () => $this->insert($ana, 'a.acme.com.br', ['company_id' => $this->acme->id, 'proof_token' => 'pessoal-da-ana'])), 'proof_token alheio');
        $this->assertTrue($this->refused(fn () => $this->insert($ana, 'b.acme.com.br', ['company_id' => $this->acme->id, 'tokens' => json_encode(['legado'])])), 'tokens legados');

        // Com o token da empresa (ou sem prova ainda), passa; e depois não troca por outro.
        $id = $this->insert($ana, 'acme.com.br', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id, 'proof_token' => $this->companyToken(), 'verified_at' => now()]);
        $this->insert($ana, 'loja.acme.com.br', ['company_id' => $this->acme->id]);
        $this->assertTrue($this->refused(fn () => DB::table('owned_domains')->where('id', $id)->update(['proof_token' => 'pessoal-da-ana'])), 'troca do proof_token');
        $this->assertTrue($this->refused(fn () => DB::table('owned_domains')->where('id', $id)->update(['tokens' => json_encode(['x'])])), 'troca dos tokens');

        // A conta pessoal continua como hoje: qualquer token dela, legados também.
        $this->insert(User::factory()->create(), 'pessoal.com.br', ['proof_token' => 'qualquer', 'tokens' => json_encode(['a', 'b'])]);
        $this->assertSame(3, DB::table('owned_domains')->where('company_id', $this->acme->id)->count() + DB::table('owned_domains')->where('domain', 'pessoal.com.br')->count());
    }

    public function test_rt_w2_the_down_refuses_while_the_company_owns_domains(): void
    {
        $ana = $this->employee();
        $this->insert($ana, 'acme.com.br', ['company_id' => $this->acme->id, 'proof_token' => $this->companyToken(), 'verified_at' => now()]);
        $migration = require database_path(self::MIGRATION);

        // No 170249a, o down passava e a linha virava posse pessoal da Ana, comprovada com o token da empresa.
        $this->assertTrue($this->refused(fn () => $migration->down()), 'down com posse da empresa');
        $this->assertTrue(Schema::hasColumn('owned_domains', 'company_id'));
        $this->assertSame(1, DB::table('owned_domains')->whereNotNull('company_id')->count());
    }

    public function test_evidencia_the_trigger_and_the_partial_uniques_hold(): void
    {
        $ana = $this->employee();
        $caio = $this->employee();
        $id = $this->insert($ana, 'acme.com.br', ['company_id' => $this->acme->id, 'sector_id' => $this->ti->id]);

        // Forjar a empresa numa linha de conta pessoal, trocar de empresa só no company_user (fora do modelo) e voltar
        // a pessoal: tudo recusado. Duas linhas do mesmo nome na empresa, por contas diferentes: recusado.
        $this->assertTrue($this->refused(fn () => $this->insert(User::factory()->create(), 'x.com.br', ['company_id' => $this->acme->id])));
        $this->assertTrue($this->refused(fn () => DB::table('owned_domains')->where('id', $id)->update(['company_id' => null, 'sector_id' => null])));
        $this->assertTrue($this->refused(fn () => $this->insert($caio, 'acme.com.br', ['company_id' => $this->acme->id])));
        // O único parcial da conta não deixa duas linhas pessoais com o mesmo nome (a corrida do firstOrCreate de hoje).
        $bia = User::factory()->create();
        $this->insert($bia, 'bia.com.br');
        $this->assertTrue($this->refused(fn () => $this->insert($bia, 'bia.com.br')));

        // Apagar o setor (FK set null) dispara o trigger, e ele aceita (a conta continua na empresa).
        DB::table('sectors')->where('id', $this->ti->id)->delete();
        $this->assertNull(DB::table('owned_domains')->where('id', $id)->value('sector_id'));

        // Até a E3a1 (P7), apagar quem cadastrou leva a posse da empresa junto (cascade em user_id).
        DB::table('users')->where('id', $ana->id)->delete();
        $this->assertSame(0, DB::table('owned_domains')->where('id', $id)->count());
    }
}
