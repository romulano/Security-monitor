<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Jobs\SendEmail;
use App\Mail\CompanyAccountDeactivated;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyInvites;
use App\Services\Company\CompanyRemoval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Red Team da E1d2 (remover, reativar e apagar depois de 30 dias). */
class RedTeamE1d2Test extends TestCase
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

    private function member(Company $company, ?int $deactivatedDaysAgo = null): CompanyMember
    {
        $user = User::factory()->create(['password' => 'senha-do-membro-1']);

        return $company->members()->forceCreate([
            'user_id' => $user->id, 'role' => CompanyRole::Member, 'joined_at' => now()->subDays(60),
            'deactivated_at' => $deactivatedDaysAgo === null ? null : now()->subDays($deactivatedDaysAgo),
        ]);
    }

    // J1 — a poda × "Reativar": a lista sai antes, a admin reativa no meio, e a poda apaga a conta reativada.
    public function test_rt_j1_a_poda_nao_apaga_a_conta_reativada_no_meio(): void
    {
        $bia = $this->member($this->acme, 31);

        // Logo depois da consulta que monta a lista, a admin reativa (a outra requisição).
        $done = false;
        DB::listen(function ($query) use (&$done, $bia) {
            if (! $done && str_contains($query->sql, 'company_user') && str_contains($query->sql, 'deactivated_at') && ! str_contains($query->sql, 'for update')) {
                $done = true;
                DB::table('company_user')->where('id', $bia->id)->update(['deactivated_at' => null]);
            }
        });

        app(CompanyRemoval::class)->purge();

        $this->assertTrue(User::query()->whereKey($bia->user_id)->exists(), 'A conta foi apagada.');
    }

    // J2 — D7: suspensa, os dados ficam intactos. A poda apaga as desativadas mesmo com a empresa suspensa (ou a chave desligada).
    public function test_rt_j2_a_poda_respeita_a_empresa_suspensa_e_a_chave_desligada(): void
    {
        $bia = $this->member($this->acme, 40);
        $this->acme->forceFill(['suspended_at' => now()])->save();

        app(CompanyRemoval::class)->purge();
        $this->assertTrue(User::query()->whereKey($bia->user_id)->exists(), 'A conta foi apagada.');

        $this->acme->forceFill(['suspended_at' => null])->save();
        config(['secmon.enterprise.enabled' => false]);
        app(CompanyRemoval::class)->purge();
        $this->assertTrue(User::query()->whereKey($bia->user_id)->exists(), 'A conta foi apagada.');
    }

    // Evidência — a poda: 29 e 30 dias ficam, 31 sai; --dry-run não apaga; admin, ativa e pessoal nunca; o que fica órfão.
    public function test_evidencia_poda_bordas_e_cascata(): void
    {
        [$outra] = $this->company('Outra', 'beto@outra.com');
        $d29 = $this->member($this->acme, 29);
        $d30 = $this->member($this->acme);
        $d30->forceFill(['deactivated_at' => now()->subDays(30)->addMinute()])->save();
        $d31 = $this->member($this->acme, 31);
        $other31 = $this->member($outra, 45);
        $active = $this->member($this->acme);
        $personal = User::factory()->create(['created_at' => now()->subYear()]);
        $invite = app(CompanyInvites::class)->invite($this->acme, User::query()->find($d31->user_id), 'eva@acme.com', CompanyRole::Member);

        $this->artisan('secmon:company-purge', ['--dry-run' => true])->expectsOutputToContain('Seriam apagadas: 2')->assertSuccessful();
        $this->assertTrue(User::query()->whereKey($d31->user_id)->exists(), 'A conta foi apagada.');

        $this->artisan('secmon:company-purge')->expectsOutputToContain('Apagadas: 2')->assertSuccessful();
        $this->assertFalse(User::query()->whereKey($d31->user_id)->exists());
        $this->assertFalse(User::query()->whereKey($other31->user_id)->exists());
        foreach ([$d29->user_id, $d30->user_id, $active->user_id, $personal->id, $this->ana->id] as $kept) {
            $this->assertTrue(User::query()->whereKey($kept)->exists());
        }
        $this->assertSame($this->ana->id, $this->acme->members()->where('role', 'admin')->sole()->user_id);
        $this->assertNull(CompanyInvite::query()->find($invite->id)->invited_by);
        $this->assertTrue(CompanyInvite::query()->find($invite->id)->isPending());
    }

    // Evidência — remover: com a senha; outros aparelhos, o "continuar conectado" e a sessão aberta caem; e-mail; IDOR e papéis.
    public function test_evidencia_remover_derruba_tudo(): void
    {
        config(['session.driver' => 'database']);
        $bia = $this->member($this->acme);
        $oldRemember = $bia->user->remember_token;
        DB::table('sessions')->insert(['id' => 'celular-da-bia', 'user_id' => $bia->user_id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        [$outra] = $this->company('Outra', 'beto@outra.com');
        $foreign = $this->member($outra);
        $caio = $this->member($this->acme);

        $this->actingAs($this->ana)->post(route('company.members.deactivate', $bia))->assertSessionHasErrorsIn('reauth', 'reauth_password');
        $this->assertFalse($bia->fresh()->isDeactivated());

        foreach ([$foreign, $this->ana->companyMembership] as $target) {
            $this->actingAs($this->ana)->post(route('company.members.deactivate', $target), ['reauth_password' => 'senha-da-admin-1'])->assertNotFound();
        }
        $this->actingAs($caio->user)->post(route('company.members.deactivate', $bia), ['reauth_password' => 'senha-do-membro-1'])->assertNotFound();
        $this->actingAs($this->ana)->post(route('company.members.reactivate', $caio))->assertNotFound();

        $this->actingAs($this->ana)->post(route('company.members.deactivate', $bia), ['reauth_password' => 'senha-da-admin-1'])->assertRedirect(route('company.accounts'));
        $this->assertTrue($bia->fresh()->isDeactivated());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $bia->user_id)->count());
        $this->assertNotSame($oldRemember, $bia->user->fresh()->remember_token);
        $this->assertFalse($foreign->fresh()->isDeactivated());
        $this->assertSame(1, Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyAccountDeactivated)->count());

        $this->actingAs($bia->user)->get(route('account.show'))->assertRedirect(route('login'));
        $this->post(route('login'), ['email' => $bia->user->email, 'password' => 'senha-do-membro-1']);
        $this->assertGuest();
    }

    // Evidência — reativar: a vaga conta os convites pendentes (em série pela trava da empresa).
    public function test_evidencia_reativar_conta_a_vaga(): void
    {
        $this->acme->forceFill(['limit_accounts' => 2])->save();
        $bia = $this->member($this->acme, 1);
        app(CompanyInvites::class)->invite($this->acme, $this->ana, 'eva@acme.com', CompanyRole::Member);

        $this->actingAs($this->ana)->post(route('company.members.reactivate', $bia), ['reauth_password' => 'senha-da-admin-1'])->assertRedirect();
        $this->assertTrue($bia->fresh()->isDeactivated());

        app(CompanyInvites::class)->cancel(CompanyInvite::query()->sole());
        $this->actingAs($this->ana)->post(route('company.members.reactivate', $bia), ['reauth_password' => 'senha-da-admin-1'])->assertRedirect(route('company.accounts'));
        $this->assertFalse($bia->fresh()->isDeactivated());
        $this->expectException(ActionRefused::class);
        app(CompanyInvites::class)->invite($this->acme, $this->ana, 'fabi@acme.com', CompanyRole::Member);
    }
}
