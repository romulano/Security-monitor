<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendEmail;
use App\Mail\CompanyEntryLink;
use App\Models\Company;
use App\Models\CompanyAuditEntry;
use App\Models\CompanyInvite;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** E1e: a auditoria da empresa (§4.9): o registro de tudo, só acréscimo, a anonimização e a tela Atividade com CSV. */
class CompanyAuditTest extends TestCase
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

    /** A empresa criada e ligada pelo painel, e a admin entrando pelo link. @return array{Company, User} */
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

    private function actions(Company $company): array
    {
        return CompanyAuditEntry::query()->where('company_id', $company->id)->orderBy('id')->pluck('action')->all();
    }

    private function playEverything(): array
    {
        [$acme, $ana] = $this->company();
        $this->actingAs($ana)->post(route('company.invites.store'), ['email' => 'bia@acme.com', 'role' => 'member']);
        $invite = CompanyInvite::query()->sole();
        $this->actingAs($ana)->post(route('company.invites.resend', $invite));
        $this->actingAs($ana)->delete(route('company.invites.cancel', $invite));
        $bia = User::factory()->create(['name' => 'Bia Souza', 'email' => 'bia.souza@acme.com']);
        $member = $acme->members()->forceCreate(['user_id' => $bia->id, 'role' => CompanyRole::Member, 'joined_at' => now()]);
        $this->actingAs($ana)->put(route('company.members.role', $member), ['role' => 'sector_manager']);
        $this->actingAs($ana)->post(route('company.members.deactivate', $member), ['reauth_password' => 'senha-da-admin-1']);
        $this->actingAs($ana)->post(route('company.members.reactivate', $member));
        $this->actingAs($this->secmon)->put(route('admin.companies.limits', $acme), ['limits' => ['accounts' => '12'] + array_fill_keys(Company::LIMITS, '')]);
        $this->actingAs($this->secmon)->post(route('admin.companies.suspend', $acme));
        $this->actingAs($this->secmon)->post(route('admin.companies.resume', $acme));

        return [$acme, $ana, $bia];
    }

    public function test_every_change_is_recorded_with_who_did_it(): void
    {
        [$acme, $ana] = $this->playEverything();

        $this->assertSame([
            'company.created', 'company.enabled', 'entry.sent', 'entry.joined', 'invite.sent', 'invite.resent', 'invite.cancelled',
            'member.role', 'member.deactivated', 'member.reactivated', 'company.limits', 'company.suspended', 'company.resumed',
        ], $this->actions($acme));

        $created = CompanyAuditEntry::query()->where('action', 'company.created')->sole();
        $this->assertSame(['secmon', $this->secmon->id, 'Carla Souza'], [$created->actor_kind, $created->actor_id, $created->actor_name]);
        $this->assertSame($ana->id, $created->target_user_id);

        $role = CompanyAuditEntry::query()->where('action', 'member.role')->sole();
        $this->assertSame(['company', $ana->id, 'ana@acme.com', 'Bia Souza'], [$role->actor_kind, $role->actor_id, $role->actor_email, $role->target_label]);
        $this->assertSame(['de' => 'Membro', 'para' => 'Responsável do setor'], $role->data);
        $this->assertSame(['contas' => 'padrão → 12'], CompanyAuditEntry::query()->where('action', 'company.limits')->sole()->data);
        $this->assertSame('bia@acme.com', CompanyAuditEntry::query()->where('action', 'invite.sent')->sole()->target_label);

        // Repetir não registra de novo: só a mudança de estado conta.
        $this->actingAs($this->secmon)->post(route('admin.companies.enable', $acme));
        $this->assertSame(1, CompanyAuditEntry::query()->where('action', 'company.enabled')->count());

        // Passar a administração também.
        $bia = User::query()->where('email', 'bia.souza@acme.com')->sole();
        $this->actingAs($ana)->post(route('company.admin.transfer'), ['member' => $bia->companyMembership->id, 'reauth_password' => 'senha-da-admin-1']);
        $this->assertSame($bia->id, CompanyAuditEntry::query()->where('action', 'admin.transferred')->sole()->target_user_id);
    }

    public function test_the_secmon_admin_actions_on_a_company_account_are_recorded(): void
    {
        config(['secmon.pentest.allowed' => true]);
        [$acme, $ana] = $this->company();
        $personal = User::factory()->create();

        $this->actingAs($this->secmon)->post(route('admin.users.password', $ana))->assertRedirect(route('admin.users'));
        $this->actingAs($this->secmon)->post(route('admin.users.pentest.grant', $ana));
        $this->actingAs($this->secmon)->post(route('admin.users.pentest.grant', $ana));
        $this->actingAs($this->secmon)->delete(route('admin.users.pentest.revoke', $ana));
        $this->actingAs($this->secmon)->delete(route('admin.users.pentest.revoke', $ana));
        // A conta pessoal não tem empresa: nada entra na auditoria.
        $this->actingAs($this->secmon)->post(route('admin.users.password', $personal));

        $rows = CompanyAuditEntry::query()->whereIn('action', ['account.password', 'tests.granted', 'tests.revoked'])->orderBy('id')->get();
        $this->assertSame(['account.password', 'tests.granted', 'tests.revoked'], $rows->pluck('action')->all());
        $this->assertSame([$acme->id], $rows->pluck('company_id')->unique()->values()->all());
        $this->assertTrue($rows->every(fn ($row) => $row->actor_kind === 'secmon' && $row->target_user_id === $ana->id));
        $this->actingAs($ana->fresh())->get(route('company.activity'))->assertSee('Nova senha gerada pela Equipe do Security Monitor: Ana Acme')->assertDontSee('Carla Security Monitor');
    }

    public function test_the_database_keeps_it_append_only_except_the_anonymization(): void
    {
        [$acme] = $this->company();
        $row = CompanyAuditEntry::query()->where('company_id', $acme->id)->where('action', 'company.created')->sole();

        foreach ([
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['action' => 'company.disabled']),
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['data' => '{"x":1}']),
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['actor_name' => 'Outra pessoa']),
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['actor_email' => 'outro@x.com']),
            fn () => DB::table('company_audit')->where('id', $row->id)->update(['target_label' => 'Outro alvo']),
            fn () => DB::table('company_audit')->where('id', $row->id)->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('O banco aceitou mudar a auditoria.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('só de acréscimo', $e->getMessage());
            }
        }

        DB::table('company_audit')->where('id', $row->id)->update(['actor_name' => 'conta removida', 'actor_email' => null, 'target_label' => 'conta removida']);
        $this->assertSame('conta removida', $row->fresh()->actor_name);
        $this->assertSame('company.created', $row->fresh()->action);
    }

    public function test_the_purge_keeps_the_record_without_the_name_and_email(): void
    {
        [$acme, $ana, $bia] = $this->playEverything();
        $bia->companyMembership->forceFill(['deactivated_at' => now()->subDays(31)])->save();
        // Bia também já tinha sido convidada pelo e-mail dela.
        DB::table('company_audit')->insert(['company_id' => $acme->id, 'actor_kind' => 'company', 'actor_id' => $bia->id, 'actor_name' => 'Bia Souza',
            'actor_email' => 'bia.souza@acme.com', 'action' => 'invite.sent', 'target_label' => 'BIA.SOUZA@acme.com', 'data' => '{}']);

        $this->artisan('secmon:company-purge')->assertSuccessful();

        $mentions = CompanyAuditEntry::query()->where(fn ($q) => $q->where('actor_id', $bia->id)->orWhere('target_user_id', $bia->id))->get();
        $this->assertNotEmpty($mentions);
        $this->assertSame(0, CompanyAuditEntry::query()->where(fn ($q) => $q->where('actor_name', 'Bia Souza')->orWhere('target_label', 'Bia Souza')
            ->orWhere('actor_email', 'bia.souza@acme.com')->orWhereRaw("lower(target_label) = 'bia.souza@acme.com'"))->count());
        $purged = CompanyAuditEntry::query()->where('action', 'member.purged')->sole();
        $this->assertSame(['system', null, 'conta removida', $bia->id], [$purged->actor_kind, $purged->actor_id, $purged->target_label, $purged->target_user_id]);
        // A admin continua com o nome dela.
        $this->assertSame('Ana Acme', CompanyAuditEntry::query()->where('actor_id', $ana->id)->first()->actor_name);
    }

    public function test_the_activity_page_is_the_admins_and_filters(): void
    {
        [$acme, $ana, $bia] = $this->playEverything();
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');

        $this->actingAs($ana)->get(route('company.activity'))
            ->assertOk()->assertSee('Atividade de Acme')->assertSee('Convite enviado')->assertSee('Equipe do Security Monitor')->assertDontSee('Carla Security Monitor')->assertDontSee('paulo@beta.com')
            ->assertSee('Empresa criada, com a admin Ana Acme')->assertSee('Papel alterado: Bia Souza (Membro → Responsável do setor)')
            ->assertSee('Convite enviado: bia@acme.com como Membro')->assertSee('A admin entrou pela primeira vez')->assertSee('Limites ajustados: contas: padrão → 12');
        $this->actingAs($ana)->get(route('company.activity', ['tipo' => 'member.role']))
            ->assertSee('Papel alterado: Bia Souza')->assertDontSee('Convite enviado: bia@acme.com');
        $this->actingAs($ana)->get(route('company.activity', ['pessoa' => 'secmon']))
            ->assertSee('Limites ajustados: contas')->assertDontSee('Papel alterado: Bia Souza');
        $this->actingAs($ana)->get(route('company.activity', ['pessoa' => $ana->id]))
            ->assertSee('Papel alterado: Bia Souza')->assertDontSee('Limites ajustados: contas');
        $this->actingAs($ana)->get(route('company.activity', ['de' => now()->addDay()->toDateString()]))->assertSee('Nada registrado');
        // Filtro inválido some, sem erro.
        $this->actingAs($ana)->get(route('company.activity', ['tipo' => 'drop table', 'de' => 'ontem']))->assertOk()->assertSee('Convite enviado');

        // A outra empresa só vê o dela; membro e conta pessoal, 404.
        $this->actingAs($paulo)->get(route('company.activity'))->assertOk()->assertDontSee('bia@acme.com');
        $this->actingAs($bia->fresh())->get(route('company.activity'))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('company.activity.csv'))->assertNotFound();
    }

    public function test_the_csv_has_the_same_filters(): void
    {
        [, $ana] = $this->playEverything();

        $response = $this->actingAs($ana)->get(route('company.activity.csv', ['tipo' => 'invite.sent']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        // Para o Excel em português: BOM, ";" e dd/mm/aaaa hh:mm; o funcionário do Security Monitor não aparece.
        $this->assertStringStartsWith("\u{FEFF}quando;quem;e-mail;origem;tipo;\"o que aconteceu\"", $csv);
        $this->assertStringContainsString(now('America/Sao_Paulo')->format('d/m/Y').' ', $csv);
        $this->assertStringContainsString(';"Ana Acme";ana@acme.com;empresa;"Convite enviado";"Convite enviado: bia@acme.com como Membro"', $csv);
        $this->assertStringNotContainsString('Papel alterado', $csv);
        $this->assertSame(2, count(array_filter(explode("\n", $csv))));

        // Tudo, com a Equipe do Security Monitor sem nome nem e-mail, e célula que começa com = vira texto.
        $ana->forceFill(['name' => '=HYPERLINK("x")'])->save();
        $this->actingAs($ana)->post(route('company.invites.store'), ['email' => 'caio@acme.com', 'role' => 'member']);
        $all = $this->actingAs($ana)->get(route('company.activity.csv'))->streamedContent();
        $this->assertStringContainsString(';"Equipe do Security Monitor";;Security Monitor;"Empresa criada";', $all);
        $this->assertStringNotContainsString('Carla Security Monitor', $all);
        $this->assertStringContainsString(';"\'=HYPERLINK(""x"")";', $all);
    }

    public function test_the_migration_goes_down_and_up(): void
    {
        $this->company();
        $migration = require database_path('migrations/2026_12_10_000100_create_company_audit_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('company_audit'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('company_audit', ['company_id', 'actor_kind', 'actor_id', 'actor_name', 'actor_email', 'action', 'target_user_id', 'target_label', 'data', 'created_at']));
    }
}
