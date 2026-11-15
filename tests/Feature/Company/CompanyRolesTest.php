<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** E1d: mudar o papel e passar a administração (§4.3), com o isolamento entre empresas. */
class CompanyRolesTest extends TestCase
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

    /** @return array{Company, User} uma empresa ativa e a admin dela, que já entrou (senha "senha-da-admin-1") */
    private function company(string $name, string $email): array
    {
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);
        $admin = User::query()->where('email', $email)->sole();
        $admin->forceFill(['email_verified_at' => now(), 'password' => 'senha-da-admin-1'])->save();
        $admin->companyMembership->forceFill(['joined_at' => now()])->save();

        return [$company, $admin];
    }

    private function member(Company $company, array $state = []): CompanyMember
    {
        $user = User::factory()->create();

        return $company->members()->forceCreate($state + ['user_id' => $user->id, 'role' => CompanyRole::Member, 'joined_at' => now()]);
    }

    private function admins(Company $company): int
    {
        return $company->members()->where('role', 'admin')->count();
    }

    public function test_the_admin_changes_the_role_of_the_others(): void
    {
        $bia = $this->member($this->acme);

        $this->actingAs($this->ana)->get(route('company.accounts'))
            ->assertSee(route('company.members.role', $bia))
            ->assertSee('(você)')
            ->assertSee('Responsável do setor: também cuida')
            ->assertSee('data-confirm-ok="Passar para '.e($bia->user->name).'"', false);
        $this->actingAs($this->ana)->put(route('company.members.role', $bia), ['role' => 'sector_manager'])->assertSessionHas('status');
        $this->assertSame(CompanyRole::SectorManager, $bia->fresh()->role);

        $this->actingAs($this->ana)->put(route('company.members.role', $bia), ['role' => 'member']);
        $this->assertSame(CompanyRole::Member, $bia->fresh()->role);

        // Admin não se dá por aqui.
        $this->actingAs($this->ana)->put(route('company.members.role', $bia), ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->assertSame(1, $this->admins($this->acme));
    }

    public function test_the_role_change_is_closed_to_others_and_to_the_admin_row(): void
    {
        $bia = $this->member($this->acme);
        $off = $this->member($this->acme, ['deactivated_at' => now()]);
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $mine = $this->ana->companyMembership;

        // A linha da admin, a desativada e a de outra empresa: 404.
        $this->actingAs($this->ana)->put(route('company.members.role', $mine), ['role' => 'member'])->assertNotFound();
        $this->actingAs($this->ana)->put(route('company.members.role', $off), ['role' => 'sector_manager'])->assertNotFound();
        $this->actingAs($paulo)->put(route('company.members.role', $bia), ['role' => 'sector_manager'])->assertNotFound();
        // Um membro não muda papel de ninguém, nem o próprio.
        $this->actingAs($bia->user)->put(route('company.members.role', $bia), ['role' => 'sector_manager'])->assertNotFound();

        $this->assertSame(CompanyRole::Member, $bia->fresh()->role);
        $this->assertSame(CompanyRole::Admin, $mine->fresh()->role);
    }

    public function test_passing_the_administration_asks_for_the_password_and_swaps_both_rows(): void
    {
        $bia = $this->member($this->acme);

        // Sem a confirmação recente, nada muda.
        $this->actingAs($this->ana)->from(route('company.accounts'))->post(route('company.admin.transfer'), ['member' => $bia->id])
            ->assertSessionHasErrors('reauth_password', null, 'reauth');
        $this->actingAs($this->ana)->from(route('company.accounts'))->post(route('company.admin.transfer'), ['member' => $bia->id, 'reauth_password' => 'errada-123'])
            ->assertSessionHasErrors('reauth_password', null, 'reauth');
        $this->assertSame(CompanyRole::Member, $bia->fresh()->role);
        // Depois do erro, a escolha continua marcada (não volta para a primeira opção).
        $this->member($this->acme);
        $this->actingAs($this->ana)->from(route('company.accounts'))->post(route('company.admin.transfer'), ['member' => $bia->id]);
        $this->actingAs($this->ana)->get(route('company.accounts'))->assertSee('value="'.$bia->id.'" selected', false)->assertSee('aria-describedby="transfer-password-error"', false);

        $this->actingAs($this->ana)->post(route('company.admin.transfer'), ['member' => $bia->id, 'reauth_password' => 'senha-da-admin-1'])
            ->assertRedirect(route('account.show'))->assertSessionHas('status');

        $this->assertSame(CompanyRole::Admin, $bia->fresh()->role);
        $this->assertSame(CompanyRole::Member, $this->ana->companyMembership->fresh()->role);
        $this->assertSame(1, $this->admins($this->acme));

        // Os dois recebem o aviso.
        $this->assertSame([$bia->user->email, 'ana@acme.com'], DB::table('email_messages')->where('kind', 'empresa-administracao')->orderBy('id')->pluck('to_address')->all());

        // A antiga perde a tela; a nova ganha.
        $this->actingAs($this->ana->fresh())->get(route('company.accounts'))->assertNotFound();
        $this->actingAs($bia->user->fresh())->get(route('company.accounts'))->assertOk();
    }

    public function test_the_new_admin_must_be_an_active_account_of_the_same_company_that_already_joined(): void
    {
        [$beta, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $foreign = $this->member($beta);
        $pending = $this->member($this->acme, ['joined_at' => null]);
        $off = $this->member($this->acme, ['deactivated_at' => now()]);
        $self = $this->ana->companyMembership;

        foreach ([$foreign, $pending, $off, $self] as $target) {
            $this->actingAs($this->ana)->from(route('company.accounts'))
                ->post(route('company.admin.transfer'), ['member' => $target->id, 'reauth_password' => 'senha-da-admin-1'])
                ->assertSessionHas('error', fn ($e) => str_contains($e, 'Escolha uma conta ativa'));
        }

        $this->assertSame(CompanyRole::Admin, $self->fresh()->role);
        $this->assertSame(1, $this->admins($this->acme));
        $this->assertSame(1, $this->admins($beta));
        $this->assertSame(CompanyRole::Member, $foreign->fresh()->role);

        // Um membro não passa a administração (a tela é só de quem administra).
        $bia = $this->member($this->acme);
        $this->actingAs($bia->user)->post(route('company.admin.transfer'), ['member' => $bia->id, 'reauth_password' => 'x'])->assertNotFound();
        $this->assertSame(CompanyRole::Admin, $self->fresh()->role);
        $this->assertNotNull($paulo);
    }
}
