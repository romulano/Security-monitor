<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Jobs\SendEmail;
use App\Mail\CompanyInviteAccepted;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyInvites;
use App\Services\Company\CompanyRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Red Team da E1d (papéis e passar a administração). */
class RedTeamE1dTest extends TestCase
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
        $admin->forceFill(['email_verified_at' => now(), 'password' => 'senha-da-admin-1'])->save();
        $admin->companyMembership->forceFill(['joined_at' => now()])->save();

        return [$company, $admin->fresh()];
    }

    private function member(Company $company, CompanyRole $role = CompanyRole::Member, bool $joined = true): CompanyMember
    {
        $user = User::factory()->create(['password' => 'senha-do-membro-1']);

        return $company->members()->forceCreate(['user_id' => $user->id, 'role' => $role, 'joined_at' => $joined ? now() : null]);
    }

    private function transfer(User $as, int $member, ?string $password = 'senha-da-admin-1')
    {
        return $this->actingAs($as)->post(route('company.admin.transfer'), array_filter(['member' => $member, 'reauth_password' => $password]));
    }

    private function adminOf(Company $company): int
    {
        return $company->members()->where('role', 'admin')->sole()->user_id;
    }

    // I1 — escalada: membro e responsável não mudam papel nem passam a administração (nem com a própria senha). O 404
    // tem de vir antes do limite: hoje o throttle roda antes do company.admin e a 6ª tentativa dá 429 (a rota existe).
    public function test_rt_i1_escalada_de_membro_e_responsavel_e_404_antes_do_limite(): void
    {
        $bia = $this->member($this->acme);
        $caio = $this->member($this->acme, CompanyRole::SectorManager);

        foreach ([$bia, $caio] as $who) {
            foreach (range(1, 6) as $i) {
                $this->actingAs($who->user)->post(route('company.admin.transfer'), ['member' => $who->id, 'reauth_password' => 'senha-do-membro-1'])->assertNotFound();
            }
            $this->actingAs($who->user)->put(route('company.members.role', $who), ['role' => 'sector_manager'])->assertNotFound();
            $this->actingAs($who->user)->put(route('company.members.role', $this->ana->companyMembership), ['role' => 'member'])->assertNotFound();
        }

        $this->assertSame($this->ana->id, $this->adminOf($this->acme));
        $this->assertSame(CompanyRole::Member, $bia->fresh()->role);
        $this->assertSame(CompanyRole::SectorManager, $caio->fresh()->role);
    }

    // Evidência — IDOR e alvos inválidos: conta de outra empresa, a própria, desativada, que não entrou, admin pelo papel.
    public function test_evidencia_alvos_da_troca_e_idor(): void
    {
        [$outra, $beto] = $this->company('Outra', 'beto@outra.com');
        $foreign = $this->member($outra);

        $this->actingAs($this->ana)->put(route('company.members.role', $foreign), ['role' => 'sector_manager'])->assertNotFound();
        $this->actingAs($this->ana)->put(route('company.members.role', $beto->companyMembership), ['role' => 'member'])->assertNotFound();

        $deactivated = $this->member($this->acme);
        $deactivated->forceFill(['deactivated_at' => now()])->save();
        $pending = $this->member($this->acme, joined: false);

        foreach ([$foreign->id, $beto->companyMembership->id, $this->ana->companyMembership->id, $deactivated->id, $pending->id, 999999] as $id) {
            $this->travel(20)->seconds();
            $this->transfer($this->ana, $id)->assertRedirect();
        }
        $this->assertSame($this->ana->id, $this->adminOf($this->acme));
        $this->assertSame($beto->id, $this->adminOf($outra));
        $this->assertSame(CompanyRole::Member, $foreign->fresh()->role);

        $bia = $this->member($this->acme);
        $this->actingAs($this->ana)->put(route('company.members.role', $bia), ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->expectException(ActionRefused::class);
        app(CompanyRoles::class)->change($bia, CompanyRole::Admin);
    }

    // A próxima requisição como num servidor de verdade: a conta é lida de novo da sessão (o teste guarda a instância).
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // Evidência — a troca: sem senha não troca; com ela, a antiga perde o acesso na requisição seguinte, a segunda troca
    // (corrida, em série pela trava da empresa) é recusada, e o papel da nova admin não muda por "papel".
    public function test_evidencia_troca_sessao_e_corrida(): void
    {
        $bia = $this->member($this->acme, CompanyRole::SectorManager);
        $caio = $this->member($this->acme);

        // A antiga admin entra pela senha (a sessão de verdade, não o actingAs).
        $this->post(route('login'), ['email' => 'ana@acme.com', 'password' => 'senha-da-admin-1']);
        $this->fresh();
        $this->post(route('company.admin.transfer'), ['member' => $bia->id])->assertSessionHasErrorsIn('reauth', 'reauth_password');
        $this->assertSame($this->ana->id, $this->adminOf($this->acme));

        $this->fresh();
        $this->post(route('company.admin.transfer'), ['member' => $bia->id, 'reauth_password' => 'senha-da-admin-1'])->assertRedirect(route('account.show'));
        $this->assertSame($bia->user_id, $this->adminOf($this->acme));

        // A mesma sessão da antiga admin, já na próxima requisição.
        $this->fresh();
        $this->get(route('company.accounts'))->assertNotFound();
        $this->assertAuthenticatedAs($this->ana);
        $this->post(route('company.admin.transfer'), ['member' => $caio->id])->assertNotFound();
        $this->put(route('company.members.role', $bia), ['role' => 'member'])->assertNotFound();

        // A segunda troca da outra aba (a trava da empresa põe as duas em série): a antiga não é mais admin.
        $this->expectRefused(fn () => app(CompanyRoles::class)->transfer($this->ana->fresh(), $caio->id));
        // "Papel" de outra aba sobre quem acabou de virar admin: recusado na trava.
        $this->expectRefused(fn () => app(CompanyRoles::class)->change($bia, CompanyRole::Member));
        $this->assertSame($bia->user_id, $this->adminOf($this->acme));
        $this->assertSame(1, $this->acme->members()->where('role', 'admin')->count());
    }

    // Evidência — o convite pendente da antiga admin continua valendo, e o aviso de aceite vai para a admin nova.
    public function test_evidencia_convites_da_antiga_admin(): void
    {
        app(CompanyInvites::class)->invite($this->acme, $this->ana, 'dani@acme.com', CompanyRole::Member);
        $link = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->last()->mail->url;
        $bia = $this->member($this->acme);

        $this->transfer($this->ana, $bia->id)->assertRedirect(route('account.show'));
        Auth::logout();
        $this->post($link, ['name' => 'Dani', 'password' => 'senha-da-dani-1', 'password_confirmation' => 'senha-da-dani-1'])->assertRedirect(route('account.show'));

        $to = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteAccepted)->map(fn ($job) => $job->mail->name)->all();
        $this->assertSame([$bia->user->name], array_values($to));
    }

    // I2 — com ENTERPRISE_ENABLED desligada, o convite e o painel são 404 sempre, nunca 429 (o "sem dizer que existe" da E1b).
    public function test_rt_i2_chave_desligada_404_antes_do_limite(): void
    {
        config(['secmon.enterprise.enabled' => false]);
        $token = str_repeat('a', 48);

        foreach (range(1, 21) as $i) {
            $this->get("/empresa/convite/{$token}")->assertNotFound();
        }

        $secmon = User::factory()->create(['is_admin' => true]);
        foreach (range(1, 11) as $i) {
            $this->actingAs($secmon)->post('/admin/empresas', ['name' => 'X', 'admin_name' => 'X', 'admin_email' => "x{$i}@x.com"])->assertNotFound();
        }
    }

    private function expectRefused(callable $action): void
    {
        try {
            $action();
            $this->fail('Não recusou.');
        } catch (ActionRefused) {
            $this->addToAssertionCount(1);
        }
    }
}
