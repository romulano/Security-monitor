<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendEmail;
use App\Mail\CompanyEntryLink;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyAuditEntry;
use App\Models\User;
use App\Services\Company\CompanyInvites;
use App\Services\Company\CompanyRemoval;
use App\Services\Company\CompanyRoles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Red Team da E1e (auditoria). Os "rt" falham na ponta; os "evidência" passam. */
class RedTeamE1eTest extends TestCase
{
    use RefreshDatabase;

    private User $secmon;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
        $this->secmon = User::factory()->create(['is_admin' => true, 'name' => 'Carla Security Monitor']);
    }

    /** @return array{Company, User} */
    private function company(string $name = 'Acme', string $email = 'ana@acme.com'): array
    {
        $this->actingAs($this->secmon)->post(route('admin.companies.store'), ['name' => $name, 'admin_name' => 'Ana '.$name, 'admin_email' => $email]);
        $company = Company::query()->where('name', $name)->sole();
        $this->actingAs($this->secmon)->post(route('admin.companies.enable', $company));
        auth()->logout();
        $link = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyEntryLink)->last()->mail->url;
        $this->post($link, ['password' => 'senha-da-admin-1', 'password_confirmation' => 'senha-da-admin-1']);
        auth()->logout();

        return [$company, User::query()->where('email', $email)->sole()];
    }

    /** Convida e aceita pelo link; devolve a conta nova. */
    private function join(Company $company, User $admin, string $email, string $name = 'Bia'): User
    {
        app(CompanyInvites::class)->invite($company, $admin, $email, CompanyRole::Member);
        $link = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;
        $this->post($link, ['name' => $name, 'password' => 'senha-do-membro-1', 'password_confirmation' => 'senha-do-membro-1']);
        auth()->logout();

        return User::query()->whereRaw('lower(email) = lower(?)', [$email])->sole();
    }

    private function csv(User $admin): string
    {
        return $this->actingAs($admin)->get(route('company.activity.csv'))->assertOk()->streamedContent();
    }

    // K1 — injeção de fórmula no CSV: o nome que a própria pessoa escolhe abre como fórmula na planilha da admin.
    public function test_rt_k1_o_csv_nao_leva_formula(): void
    {
        [$acme, $ana] = $this->company();
        foreach (['=HYPERLINK("https://golpe.example","clique")', '+1+1', '-2+3', '@SUM(1)', "\t=1"] as $i => $name) {
            $bia = $this->join($acme, $ana, "p{$i}@acme.com", $name);
            app(CompanyRoles::class)->change($bia->companyMembership, CompanyRole::SectorManager, $bia);
        }

        // O CSV usa ";" (Excel em português, rodada 5 da Designer).
        $rows = array_map(fn ($line) => str_getcsv($line, ';'), array_filter(explode("\n", $this->csv($ana))));
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, "Célula que a planilha executa: {$cell}");
            }
        }
    }

    // K2 — TRUNCATE não passa pelo trigger de linha: apaga a auditoria inteira.
    public function test_rt_k2_truncate_e_recusado(): void
    {
        $this->company();
        $before = CompanyAuditEntry::query()->count();

        try {
            DB::transaction(fn () => DB::statement('truncate company_audit'));
        } catch (QueryException) {
            // recusar é o desejado
        }

        $this->assertSame($before, CompanyAuditEntry::query()->count(), 'TRUNCATE apagou a auditoria.');
    }

    // K3 — a poda anonimiza pelo e-mail de hoje: o e-mail do convite, se a pessoa trocou depois, fica na auditoria.
    public function test_rt_k3_a_poda_nao_deixa_o_email_antigo_do_convite(): void
    {
        [$acme, $ana] = $this->company();
        $bia = $this->join($acme, $ana, 'bia.antigo@acme.com');
        $bia->forceFill(['email' => 'bia.novo@acme.com'])->save();
        app(CompanyRemoval::class)->deactivate($bia->companyMembership, $ana);
        DB::table('company_user')->where('user_id', $bia->id)->update(['deactivated_at' => now()->subDays(31)]);

        app(CompanyRemoval::class)->purge();

        $this->assertFalse(User::query()->whereKey($bia->id)->exists());
        $dump = json_encode(DB::table('company_audit')->get()).$this->csv($ana);
        $this->assertStringNotContainsString('bia.antigo@acme.com', $dump, 'O e-mail do convite da conta apagada ficou na auditoria.');
        $this->assertStringNotContainsString('bia.novo@acme.com', $dump);
        $this->assertStringNotContainsString('Bia', $dump);
    }

    // K4 — a empresa vê o nome e o e-mail pessoal de quem da equipe do Security Monitor criou, ligou ou suspendeu a empresa.
    public function test_rt_k4_a_empresa_nao_ve_o_email_da_equipe_do_secmon(): void
    {
        [, $ana] = $this->company();

        $page = $this->actingAs($ana)->get(route('company.activity'))->assertOk()->getContent();
        $csv = $this->csv($ana);

        foreach ([$this->secmon->email, 'Carla Security Monitor'] as $secret) {
            $this->assertStringNotContainsString($secret, $page.$csv, "A empresa vê {$secret}.");
        }
    }

    // Evidência — o trigger: DELETE e UPDATE fora da anonimização recusados; a FK restrict barra apagar a empresa.
    // Documenta também o que ele NÃO cobre: o usuário do app é dono da tabela e desliga o trigger.
    public function test_evidencia_trigger(): void
    {
        [$acme] = $this->company();
        $row = CompanyAuditEntry::query()->first();

        foreach ([
            fn () => DB::table('company_audit')->where('id', $row->id)->delete(),
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['action' => 'nada']),
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['data' => json_encode(['x' => 1])]),
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['actor_name' => 'Outra pessoa']),
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['created_at' => now()->subYear()]),
            fn () => DB::table('companies')->where('id', $acme->id)->delete(),
        ] as $i => $write) {
            try {
                DB::transaction($write);
                $this->fail("Escrita {$i} passou.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Permitido: "anonimizar" qualquer linha (o app pode apagar quem fez, sem apagar a conta).
        DB::transaction(fn () => DB::table('company_audit')->where('id', $row->id)->update(['actor_name' => 'conta removida', 'actor_email' => null]));
        // Permitido ao dono da tabela: desligar o trigger (o usuário do app em prod é o mesmo do banco).
        DB::transaction(function () use ($row) {
            DB::statement('alter table company_audit disable trigger company_audit_append_only');
            DB::table('company_audit')->where('id', $row->id)->update(['action' => 'forjado']);
            DB::statement('alter table company_audit enable trigger company_audit_append_only');
        });
        $this->assertSame('forjado', CompanyAuditEntry::query()->find($row->id)->action);
    }

    // Evidência — isolamento: a Atividade e o CSV são só da própria empresa; pessoa de outra empresa no filtro dá vazio;
    // filtro inválido some; membro leva 404; cada ação registrada uma vez.
    public function test_evidencia_isolamento_e_contagem(): void
    {
        [$acme, $ana] = $this->company();
        [$outra, $beto] = $this->company('Outra', 'beto@outra.com');
        $bia = $this->join($acme, $ana, 'bia@acme.com');
        $this->join($outra, $beto, 'caio@outra.com', 'Caio Outra');

        $this->actingAs($ana)->get(route('company.activity', ['pessoa' => $beto->id]))->assertOk()->assertDontSee('Caio Outra')->assertDontSee('beto@outra.com');
        $this->actingAs($ana)->get(route('company.activity', ['de' => 'ontem', 'ate' => '2026-13-40', 'pessoa' => 'x', 'tipo' => "'; drop table"]))->assertOk();
        $csv = $this->csv($ana);
        $this->assertStringNotContainsString('outra.com', $csv);
        $this->assertStringNotContainsString('Caio', $csv);
        $this->actingAs($bia)->get(route('company.activity'))->assertNotFound();
        $this->actingAs($bia)->get(route('company.activity.csv'))->assertNotFound();

        $counts = CompanyAuditEntry::query()->where('company_id', $acme->id)->pluck('action')->countBy()->all();
        foreach (['company.created', 'company.enabled', 'entry.sent', 'entry.joined', 'invite.sent', 'invite.accepted'] as $action) {
            $this->assertSame(1, $counts[$action] ?? 0, "{$action} registrado ".($counts[$action] ?? 0).' vez(es)');
        }
        $this->assertNull(CompanyAuditEntry::query()->where('action', 'invite.sent')->first()->data['email'] ?? null);
    }
}
