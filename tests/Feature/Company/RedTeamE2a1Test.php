<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\Sectors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Red Team da E2a1 (setores: estrutura, criar e renomear). */
class RedTeamE2a1Test extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
        [$this->acme, $this->ana] = $this->company('Acme', 'ana@acme.com');
    }

    /** @return array{Company, User} */
    private function company(string $name, string $email): array
    {
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);
        $admin = User::query()->where('email', $email)->sole();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->companyMembership->forceFill(['joined_at' => now()])->save();

        return [$company, $admin->fresh()];
    }

    private function member(Company $company, CompanyRole $role = CompanyRole::Member): CompanyMember
    {
        $user = User::factory()->create();

        return $company->members()->forceCreate(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
    }

    private function sector(string $name, ?User $admin = null): Sector
    {
        $this->actingAs($admin ?? $this->ana)->post(route('company.sectors.store'), ['name' => $name])->assertRedirect(route('company.sectors'));

        return Sector::query()->orderByDesc('id')->firstOrFail();
    }

    private function seat(Sector $sector, CompanyMember $member): void
    {
        DB::table('sector_user')->insert(['sector_id' => $sector->id, 'user_id' => $member->user_id, 'is_manager' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** O banco recusa a escrita; o savepoint deixa a transação do teste viva. */
    private function assertRefused(callable $write, string $message): void
    {
        try {
            DB::transaction(fn () => $write());
        } catch (QueryException) {
            return;
        }

        $this->fail($message);
    }

    // L1 — o trigger só olha `sector_user`: mudar `sectors.company_id` leva as contas da Acme para um setor da Beta.
    public function test_rt_l1_o_setor_nao_muda_de_empresa(): void
    {
        $ti = $this->sector('TI');
        $this->seat($ti, $this->member($this->acme));
        [$beta] = $this->company('Beta', 'paulo@beta.com');

        $this->assertRefused(fn () => DB::table('sectors')->where('id', $ti->id)->update(['company_id' => $beta->id]),
            'O setor mudou de empresa com contas de outra empresa dentro.');

        $this->assertSame($this->acme->id, $ti->fresh()->company_id);
    }

    // L2 — a conta muda de empresa (ou perde a linha em company_user) e fica no setor da empresa antiga.
    public function test_rt_l2_a_conta_do_setor_nao_sai_da_empresa_por_baixo(): void
    {
        $ti = $this->sector('TI');
        $caio = $this->member($this->acme);
        $this->seat($ti, $caio);
        [$beta] = $this->company('Beta', 'paulo@beta.com');

        $this->assertRefused(fn () => DB::table('company_user')->where('id', $caio->id)->update(['company_id' => $beta->id]),
            'A conta passou para a Beta e continua no setor da Acme.');

        // Sem a linha da empresa (apagada fora do modelo), a conta vira pessoal: não pode ficar no setor.
        DB::table('company_user')->where('id', $caio->id)->delete();
        $this->assertSame(0, DB::table('sector_user')->where('user_id', $caio->user_id)->count(), 'Conta pessoal dentro de um setor.');
    }

    // L3 — NUL no nome: o Postgres recusa o byte e a tela leva 500 em vez da mensagem.
    public function test_rt_l3_nome_com_byte_nulo_ou_controle_nao_da_500(): void
    {
        $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => "TI\0"])->assertRedirect();
        $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => "Fin\x07anceiro"])->assertRedirect();
        $this->assertSame(0, $this->acme->sectors()->where('name', 'like', '%'.chr(7).'%')->count(), 'Caractere de controle gravado no nome.');
    }

    // L4 — nomes que a tela mostra iguais passam como diferentes: espaço de largura zero, sinal de direção e NFD × NFC.
    public function test_rt_l4_nome_igual_na_tela_e_recusado(): void
    {
        $this->sector('Financeiro');
        $this->sector('Café');

        foreach (["Finan\u{200B}ceiro", "Financeiro\u{200E}", "\u{FEFF}Financeiro", "Cafe\u{0301}"] as $twin) {
            $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => $twin])->assertSessionHasErrors('name');
        }

        $this->assertSame(2, $this->acme->sectors()->count(), 'Dois setores com o mesmo nome na tela.');
    }

    // L5 — a admin que passou a administração no meio do pedido: o papel é lido antes de travar a empresa.
    public function test_rt_l5_a_admin_que_passou_a_administracao_no_meio_nao_cria_setor(): void
    {
        $bia = $this->member($this->acme);
        $done = false;
        DB::listen(function ($query) use (&$done, $bia) {
            // Logo depois de ler o papel (antes da trava da empresa), a outra requisição da admin passa a administração.
            if (! $done && str_contains($query->sql, 'from "company_user"') && ! str_contains($query->sql, 'for update')) {
                $done = true;
                DB::table('company_user')->where('user_id', $this->ana->id)->update(['role' => CompanyRole::Member->value]);
                DB::table('company_user')->where('id', $bia->id)->update(['role' => CompanyRole::Admin->value]);
            }
        });

        // O middleware já passou (a requisição começou com ela admin): chama o serviço como o controller.
        try {
            app(Sectors::class)->create($this->ana, 'Depois da troca');
        } catch (ActionRefused) {
        }

        $this->assertSame(0, $this->acme->sectors()->count(), 'A antiga admin criou um setor depois de passar a administração.');
    }

    // Evidência — 404 antes do throttle: membro, responsável e a chave desligada nunca levam 429 (nem descobrem a rota).
    public function test_evidencia_404_antes_do_throttle(): void
    {
        $ti = $this->sector('TI');
        $caio = $this->member($this->acme)->user;
        $bia = $this->member($this->acme, CompanyRole::SectorManager)->user;

        foreach ([$caio, $bia] as $who) {
            foreach (range(1, 22) as $i) {
                $this->actingAs($who)->post(route('company.sectors.store'), ['name' => "X{$i}"])->assertNotFound();
            }
            foreach (range(1, 32) as $i) {
                $this->actingAs($who)->put(route('company.sectors.rename', $ti), ['name' => "Y{$i}"])->assertNotFound();
            }
        }

        config(['secmon.enterprise.enabled' => false]);
        foreach (range(1, 22) as $i) {
            $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => "Z{$i}"])->assertNotFound();
        }

        $this->assertSame(['TI'], $this->acme->sectors()->pluck('name')->all());
    }

    // Evidência — o nome único sem caixa vale com acento e com o İ turco (o lower() do banco nos dois lados).
    public function test_evidencia_caixa_com_acento_e_i_turco(): void
    {
        $this->sector('Comunicação');
        $this->sector('İnovação');

        foreach (['COMUNICAÇÃO', 'comunicaçÃo', '  comunicação  ', 'İNOVAÇÃO'] as $twin) {
            $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => $twin])->assertSessionHasErrors('name');
        }

        // 60 caracteres com acento cabem (o banco conta caracteres, não bytes); 61 não.
        $this->sector(str_repeat('ç', 60));
        $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => str_repeat('é', 61)])->assertSessionHasErrors('name');
        $this->assertSame(3, $this->acme->sectors()->count());
    }

    // Evidência — o trigger recusa por update de sector_id e de user_id, e o mass assignment do setor não pega company_id.
    public function test_evidencia_trigger_no_update_e_mass_assignment(): void
    {
        $ti = $this->sector('TI');
        [$beta, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $vendas = $this->sector('Vendas', $paulo);
        $caio = $this->member($this->acme);
        $this->seat($ti, $caio);

        foreach ([['sector_id' => $vendas->id], ['user_id' => $paulo->id]] as $change) {
            $this->assertRefused(fn () => DB::table('sector_user')->where('sector_id', $ti->id)->update($change), 'O trigger deixou: '.json_encode($change));
        }

        $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => 'RH', 'company_id' => $beta->id, 'id' => 999]);
        $this->assertSame($this->acme->id, Sector::query()->where('name', 'RH')->sole()->company_id);
        $this->actingAs($this->ana)->put(route('company.sectors.rename', $ti), ['name' => 'TI 2', 'company_id' => $beta->id]);
        $this->assertSame($this->acme->id, $ti->fresh()->company_id);
    }
}
