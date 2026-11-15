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
use Tests\TestCase;
use Throwable;

/** E1a: integridade da empresa contra corrida, Admin → Usuários, atribuição em massa e linha apagada (Red Team). */
class CompanyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function companies(): Companies
    {
        return app(Companies::class);
    }

    private function admin(string $email = 'ana@acme.com'): User
    {
        return User::query()->where('email', $email)->sole();
    }

    // A3 — corrida na criação: outro pedido grava o mesmo e-mail entre o exists() e o insert.
    public function test_rt_a3_corrida_no_mesmo_email_vira_recusa_e_nao_erro_500(): void
    {
        User::creating(function (User $user) {
            if ($user->email === 'ana@acme.com' && ! DB::table('users')->where('email', 'ana@acme.com')->exists()) {
                DB::table('users')->insert(['name' => 'Outro pedido', 'email' => 'ana@acme.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        try {
            $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
            $this->fail('Criou duas contas com o mesmo e-mail.');
        } catch (ActionRefused) {
            $this->addToAssertionCount(1);
        } catch (QueryException $e) {
            $this->fail('A corrida vira erro 500 (e loga o INSERT com o hash da senha): '.class_basename($e));
        } finally {
            User::flushEventListeners();
        }
    }

    // A4 — Admin → Usuários apaga a admin da empresa e deixa a empresa sem admin.
    public function test_rt_a4_admin_usuarios_nao_remove_conta_de_empresa(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $ana = $this->admin();
        $secmon = User::factory()->create(['is_admin' => true]);

        $this->actingAs($secmon)->delete(route('admin.users.destroy', $ana));

        $this->assertModelExists($ana);
        $this->assertSame(1, $acme->members()->where('role', 'admin')->count(), 'A empresa ficou sem admin.');
    }

    // A5 — atribuição em massa no CompanyMember ($guarded = []): papel, empresa e id vêm do array.
    public function test_rt_a5_membro_nao_aceita_papel_nem_empresa_por_atribuicao_em_massa(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $outra = $this->companies()->create('Outra', 'Beto', 'beto@outra.com');
        $membro = User::factory()->create();
        $acme->members()->forceCreate(['user_id' => $membro->id, 'role' => CompanyRole::Member]);
        $linha = $membro->companyMembership;

        try {
            // Como ficaria um "update($request->validated())" descuidado numa tela da E1d.
            $linha->update(['role' => 'sector_manager', 'company_id' => $outra->id, 'deactivated_at' => null, 'user_id' => $membro->id]);
        } catch (Throwable) {
            // MassAssignmentException também serve.
        }

        $linha = $linha->fresh();
        $this->assertSame(CompanyRole::Member, $linha->role, 'O papel mudou por atribuição em massa.');
        $this->assertSame($acme->id, $linha->company_id, 'A conta mudou de empresa por atribuição em massa.');
    }

    // A6 — apagar a linha de company_user transforma a conta de empresa em pessoal (R12 ao contrário).
    public function test_rt_a6_a_linha_da_empresa_nao_e_apagada_sozinha(): void
    {
        $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $ana = $this->admin();

        try {
            $ana->companyMembership->delete();
        } catch (Throwable) {
            // recusado: é o desejado
        }

        $this->assertTrue($ana->fresh()->isCompanyAccount(), 'A conta de empresa virou pessoal (e a empresa ficou sem admin).');
    }

    // Evidência — limites: o banco barra negativo e estouro; a tela da E1b precisa validar antes (senão 500).
    public function test_evidencia_limites_negativo_e_estouro_param_no_banco(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');

        foreach ([-1, 2147483648] as $valor) {
            try {
                DB::transaction(fn () => DB::table('companies')->where('id', $acme->id)->update(['limit_accounts' => $valor]));
                $this->fail("O banco aceitou {$valor}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // Evidência — o token da empresa não vaza pela conta, pelo membro nem pela lista de contas.
    public function test_evidencia_token_nao_vaza_pelas_relacoes(): void
    {
        $acme = $this->companies()->create('Acme', 'Ana', 'ana@acme.com');
        $token = $acme->domain_token;
        $ana = $this->admin();

        $this->assertStringNotContainsString($token, $ana->load('company', 'companyMembership.company')->toJson());
        $this->assertStringNotContainsString($token, Company::query()->with('users', 'members.company')->get()->toJson());
        $this->assertStringNotContainsString($token, json_encode($ana->company->attributesToArray()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $token);
    }
}
