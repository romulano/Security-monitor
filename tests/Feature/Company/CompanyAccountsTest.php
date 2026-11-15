<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendEmail;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyInvites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** E1c2: a página "Contas da empresa" (convidar, reenviar, cancelar), o isolamento entre empresas e quem ocupa o e-mail. */
class CompanyAccountsTest extends TestCase
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

    /** @return array{Company, User} uma empresa ativa e a admin dela, que já entrou */
    private function company(string $name, string $email): array
    {
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);
        $admin = User::query()->where('email', $email)->sole();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->companyMembership->forceFill(['joined_at' => now()])->save();

        return [$company, $admin];
    }

    private function member(Company $company, CompanyRole $role = CompanyRole::Member): User
    {
        $user = User::factory()->create();
        $company->members()->forceCreate(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    }

    private function openInvite(Company $company, string $email): CompanyInvite
    {
        $admin = $company->members()->where('role', 'admin')->sole()->user;
        app(CompanyInvites::class)->invite($company, $admin, $email, CompanyRole::Member);

        return CompanyInvite::query()->where('email', $email)->sole();
    }

    public function test_only_the_company_admin_sees_the_page(): void
    {
        $this->openInvite($this->acme, 'bia@acme.com');

        $this->actingAs($this->ana)->get(route('company.accounts'))
            ->assertOk()
            ->assertSee('Contas de Acme')
            ->assertSee('2 de 10 contas em uso')
            ->assertSee('bia@acme.com')
            ->assertSee('vale até');

        foreach ([$this->member($this->acme), $this->member($this->acme, CompanyRole::SectorManager), User::factory()->create(), User::factory()->create(['is_admin' => true])] as $user) {
            $this->actingAs($user)->get(route('company.accounts'))->assertNotFound();
        }

        config(['secmon.enterprise.enabled' => false]);
        $this->actingAs($this->ana)->get(route('company.accounts'))->assertNotFound();
        auth()->logout();
        config(['secmon.enterprise.enabled' => true]);
        $this->get(route('company.accounts'))->assertRedirect(route('login'));
    }

    public function test_the_admin_invites_from_the_page(): void
    {
        $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => 'Bia@Acme.com', 'role' => 'sector_manager'])
            ->assertRedirect(route('company.accounts'))->assertSessionHas('status');

        $invite = CompanyInvite::query()->sole();
        $this->assertSame('bia@acme.com', $invite->email);
        $this->assertSame(CompanyRole::SectorManager, $invite->role);
        $this->assertSame($this->ana->id, $invite->invited_by);
        $this->assertCount(1, Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink));

        // Admin não se convida, e papel inventado não passa.
        foreach (['admin', 'owner', ''] as $role) {
            $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => 'chefe@acme.com', 'role' => $role])->assertSessionHasErrors('role');
        }

        // E-mail de conta pessoal: recusado, com a explicação.
        User::factory()->create(['email' => 'pessoa@gmail.com']);
        $this->actingAs($this->ana)->from(route('company.accounts'))->post(route('company.invites.store'), ['email' => 'PESSOA@gmail.com', 'role' => 'member'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'convide outro e-mail'));
        $this->assertSame(1, CompanyInvite::query()->count());
    }

    public function test_the_page_closes_the_form_when_every_seat_is_taken(): void
    {
        app(Companies::class)->setLimits($this->acme, ['accounts' => 2]);
        $this->openInvite($this->acme, 'bia@acme.com');

        $this->actingAs($this->ana)->get(route('company.accounts'))->assertSee('2 de 2 contas em uso')->assertSee('Todas as vagas estão em uso');
        $this->actingAs($this->ana)->from(route('company.accounts'))->post(route('company.invites.store'), ['email' => 'caio@acme.com', 'role' => 'member'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, '2 de 2'));
    }

    public function test_resend_and_cancel(): void
    {
        $invite = $this->openInvite($this->acme, 'bia@acme.com');
        $hash = $invite->token_hash;

        $this->actingAs($this->ana)->post(route('company.invites.resend', $invite))->assertSessionHas('status');
        $this->assertNotSame($hash, $invite->fresh()->token_hash);

        $this->actingAs($this->ana)->delete(route('company.invites.cancel', $invite))->assertSessionHas('status');
        $this->assertNotNull($invite->fresh()->revoked_at);

        // Cancelado não volta.
        $this->actingAs($this->ana)->post(route('company.invites.resend', $invite))->assertNotFound();
        $this->actingAs($this->ana)->get(route('company.accounts'))->assertDontSee('bia@acme.com');
    }

    public function test_another_company_or_a_member_cannot_touch_the_invite(): void
    {
        $invite = $this->openInvite($this->acme, 'bia@acme.com');
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $member = $this->member($this->acme);
        $hash = $invite->token_hash;

        foreach ([$paulo, $member] as $user) {
            $this->actingAs($user)->post(route('company.invites.resend', $invite))->assertNotFound();
            $this->actingAs($user)->delete(route('company.invites.cancel', $invite))->assertNotFound();
        }
        $this->actingAs($paulo)->get(route('company.accounts'))->assertOk()->assertDontSee('bia@acme.com');
        $this->actingAs($this->ana)->post('/empresa/contas/convites/999999/reenviar')->assertNotFound();

        $invite->refresh();
        $this->assertSame($hash, $invite->token_hash);
        $this->assertNull($invite->revoked_at);
    }

    public function test_the_account_page_links_the_admin_only(): void
    {
        $this->actingAs($this->ana)->get(route('account.show'))->assertSee(route('company.accounts'));
        $this->actingAs($this->member($this->acme))->get(route('account.show'))->assertOk()->assertDontSee(route('company.accounts'));
        $this->actingAs(User::factory()->create())->get(route('account.show'))->assertOk()->assertDontSee(route('company.accounts'));
    }

    public function test_the_secmon_panel_shows_who_holds_the_email(): void
    {
        $secmon = User::factory()->create(['is_admin' => true]);
        $personal = User::factory()->unverified()->create(['name' => 'Pessoa Antiga', 'email' => 'pessoa@empresa.com']);

        $this->actingAs($secmon)->from(route('admin.companies.index'))
            ->post(route('admin.companies.store'), ['name' => 'Nova', 'admin_name' => 'Fulana', 'admin_email' => 'Pessoa@Empresa.com'])
            ->assertRedirect(route('admin.companies.index'))
            ->assertSessionHas('error');

        $this->actingAs($secmon)->get(route('admin.companies.index'))
            ->assertSee('Pessoa Antiga')
            ->assertSee('conta pessoal')
            ->assertSee('e-mail não confirmado')
            ->assertSee('action="'.route('admin.users.destroy', $personal).'"', false)
            ->assertSee('value="Fulana"', false);

        // Conta de empresa: diz de qual, sem botão de remover.
        $this->actingAs($secmon)->from(route('admin.companies.index'))
            ->post(route('admin.companies.store'), ['name' => 'Nova', 'admin_name' => 'Fulana', 'admin_email' => 'ana@acme.com']);
        $this->actingAs($secmon)->get(route('admin.companies.index'))
            ->assertSee('conta da empresa Acme')
            ->assertDontSee('action="'.route('admin.users.destroy', $this->ana).'"', false);

        // Remover a conta pessoal libera o e-mail; nunca há conversão.
        $this->actingAs($secmon)->delete(route('admin.users.destroy', $personal))->assertSessionHas('status');
        $this->actingAs($secmon)->post(route('admin.companies.store'), ['name' => 'Nova', 'admin_name' => 'Fulana', 'admin_email' => 'pessoa@empresa.com'])->assertSessionHas('status');
        $this->assertTrue(User::query()->where('email', 'pessoa@empresa.com')->sole()->isCompanyAccount());
    }
}
