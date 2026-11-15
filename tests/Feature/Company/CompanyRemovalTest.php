<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendEmail;
use App\Mail\CompanyAccountDeactivated;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\MonitoredDomain;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyInvites;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** E1d2: remover (desativar), reativar e apagar depois de 30 dias (§4.6, D9). */
class CompanyRemovalTest extends TestCase
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

    private function remove(CompanyMember $member, ?string $password = 'senha-da-admin-1')
    {
        return $this->actingAs($this->ana)->from(route('company.accounts'))
            ->post(route('company.members.deactivate', $member), array_filter(['reauth_password' => $password]));
    }

    public function test_removing_asks_for_the_password_and_closes_the_account_at_once(): void
    {
        $bia = $this->member($this->acme);
        $token = $bia->user->remember_token;

        $this->remove($bia, null)->assertSessionHasErrors('reauth_password', null, 'reauth');
        $this->remove($bia, 'errada-123')->assertSessionHasErrors('reauth_password', null, 'reauth');
        $this->assertNull($bia->fresh()->deactivated_at);

        $this->remove($bia)->assertRedirect(route('company.accounts'))->assertSessionHas('status');
        $bia->refresh();
        $this->assertNotNull($bia->deactivated_at);
        $this->assertNotSame($token, $bia->user->fresh()->remember_token);

        // O e-mail sai para a própria conta.
        $mail = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyAccountDeactivated)->sole()->mail;
        $this->assertSame('Acme', $mail->company);
        $this->assertSame($bia->user->email, DB::table('email_messages')->where('kind', 'empresa-conta-desativada')->value('to_address'));

        // A sessão aberta cai, e a conta não vira pessoal.
        $this->actingAs($bia->user->fresh())->get(route('account.show'))
            ->assertRedirect(route('login'))->assertSessionHas('error', fn ($e) => str_contains($e, 'desativada'));
        $this->assertTrue($bia->user->fresh()->isCompanyAccount());
        $this->actingAs($this->ana)->get(route('company.accounts'))->assertSee('removida em')->assertSee('se não for reativada')->assertSee('name="reauth_password"', false)->assertSee(route('company.members.reactivate', $bia));
    }

    public function test_the_admin_others_and_strangers_cannot_remove(): void
    {
        $bia = $this->member($this->acme);
        $caio = $this->member($this->acme);
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $mine = $this->ana->companyMembership;

        // A admin não remove a si mesma (passa a administração antes).
        $this->remove($mine)->assertNotFound();
        // Outra empresa e um membro: 404.
        $this->actingAs($paulo)->post(route('company.members.deactivate', $bia), ['reauth_password' => 'senha-da-admin-1'])->assertNotFound();
        $this->actingAs($caio->user)->post(route('company.members.deactivate', $bia), ['reauth_password' => 'password'])->assertNotFound();
        // Já removida: 404.
        $this->remove($bia);
        $this->remove($bia)->assertNotFound();

        $this->assertNull($mine->fresh()->deactivated_at);
        $this->assertNull($caio->fresh()->deactivated_at);
        $this->assertSame(1, Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyAccountDeactivated)->count());
    }

    public function test_reactivating_needs_the_password_and_a_free_seat(): void
    {
        app(Companies::class)->setLimits($this->acme, ['accounts' => 3]);
        $bia = $this->member($this->acme);
        $this->remove($bia);

        // Desativada não ocupa vaga: o convite ocupa a dela; reativar não cabe.
        $this->member($this->acme);
        app(CompanyInvites::class)->invite($this->acme, $this->ana, 'novo@acme.com', CompanyRole::Member);
        $this->actingAs($this->ana)->from(route('company.accounts'))->post(route('company.members.reactivate', $bia), ['reauth_password' => 'senha-da-admin-1'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, '3 de 3'));
        $this->assertNotNull($bia->fresh()->deactivated_at);

        app(CompanyInvites::class)->cancel($this->acme->invites()->sole());
        $this->actingAs($this->ana)->post(route('company.members.reactivate', $bia), ['reauth_password' => 'senha-da-admin-1'])->assertSessionHas('status');
        $this->assertNull($bia->fresh()->deactivated_at);
        $this->actingAs($bia->user->fresh())->get(route('account.show'))->assertOk();

        // Ativa não se reativa; de outra empresa, 404.
        $this->actingAs($this->ana)->post(route('company.members.reactivate', $bia), ['reauth_password' => 'senha-da-admin-1'])->assertNotFound();
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $bia->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($paulo)->post(route('company.members.reactivate', $bia), ['reauth_password' => 'senha-da-admin-1'])->assertNotFound();
        $this->assertNotNull($bia->fresh()->deactivated_at);
    }

    public function test_after_thirty_days_the_removed_account_is_erased(): void
    {
        $old = $this->member($this->acme, ['deactivated_at' => now()->subDays(31)]);
        $recent = $this->member($this->acme, ['deactivated_at' => now()->subDays(29)]);
        $active = $this->member($this->acme);
        $personal = User::factory()->create();
        $oldUser = $old->user;

        $this->artisan('secmon:company-purge', ['--dry-run' => true])->expectsOutputToContain('Seriam apagadas: 1')->assertSuccessful();
        $this->assertModelExists($oldUser);

        $this->artisan('secmon:company-purge')->expectsOutputToContain('Apagadas: 1')->assertSuccessful();

        $this->assertModelMissing($oldUser);
        $this->assertFalse(CompanyMember::query()->whereKey($old->id)->exists());
        $this->assertModelExists($recent->user);
        $this->assertModelExists($active->user);
        $this->assertModelExists($personal);
        $this->assertModelExists($this->ana);
        $this->assertSame(1, $this->acme->members()->where('role', 'admin')->count());

        $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command);
        $this->assertTrue($events->contains(fn ($command) => str_contains((string) $command, 'secmon:company-purge')));
    }

    public function test_an_account_with_security_test_records_is_kept(): void
    {
        $tested = $this->member($this->acme, ['deactivated_at' => now()->subDays(40)]);
        $plain = $this->member($this->acme, ['deactivated_at' => now()->subDays(40)]);
        $user = $tested->user;
        $monitored = MonitoredDomain::query()->create(['user_id' => $user->id, 'domain' => 'acme.com.br', 'verify_token' => 'abc123']);
        $domain = DB::table('pentest_domains')->insertGetId(['user_id' => $user->id, 'domain_id' => $monitored->id, 'host' => 'acme.com.br', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pentest_consents')->insert(['pentest_domain_id' => $domain, 'user_id' => $user->id, 'version' => 'v1', 'text_sha256' => str_repeat('a', 64),
            'rule' => '{}', 'rule_sha256' => str_repeat('b', 64), 'rendered_sha256' => str_repeat('c', 64), 'declarations' => '[]',
            'accepted_at' => now()->subDays(50), 'reauth_method' => 'password', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('secmon:company-purge', ['--dry-run' => true])
            ->expectsOutputToContain('Seriam apagadas: 1')
            ->expectsOutputToContain("Mantida: tem registro de teste (conta #{$user->id})")
            ->assertSuccessful();
        $this->artisan('secmon:company-purge')->expectsOutputToContain('Apagadas: 1')->assertSuccessful();

        $this->assertModelExists($user);
        $this->assertNotNull($tested->fresh()->deactivated_at);
        $this->assertSame(1, DB::table('pentest_consents')->where('user_id', $user->id)->count());
        $this->assertFalse(CompanyMember::query()->whereKey($plain->id)->exists());
    }

    public function test_the_accepted_invite_email_points_to_the_real_removal(): void
    {
        $this->assertStringContainsString('Remova a conta em Contas da empresa', file_get_contents(resource_path('views/emails/company-invite-accepted.blade.php')));
        $this->assertStringNotContainsString('contato@example.com', file_get_contents(resource_path('views/emails/company-invite-accepted-text.blade.php')));
    }
}
