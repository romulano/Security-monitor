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

/** Red Team da E1c2 (Contas da empresa). Os "rt" falham na ponta; os "evidência" passam. */
class RedTeamE1c2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
    }

    /** @return array{Company, User} */
    private function company(string $name, string $email): array
    {
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);
        $admin = User::query()->where('email', $email)->sole();
        $admin->forceFill(['email_verified_at' => now()])->save();

        return [$company, $admin];
    }

    private function member(Company $company, CompanyRole $role): User
    {
        $user = User::factory()->create();
        $company->members()->forceCreate(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    }

    // Evidência — IDOR e papéis: só a admin da própria empresa, o mesmo 404 para convite de outra empresa, inexistente,
    // aceito ou cancelado; responsável, membro, admin desativada e empresa suspensa não passam.
    public function test_evidencia_idor_e_papeis(): void
    {
        [$acme, $ana] = $this->company('Acme', 'ana@acme.com');
        [$outra, $beto] = $this->company('Outra', 'beto@outra.com');
        $foreign = app(CompanyInvites::class)->invite($outra, $beto, 'caio@outra.com', CompanyRole::Member);
        $mine = app(CompanyInvites::class)->invite($acme, $ana, 'bia@acme.com', CompanyRole::Member);

        foreach ([$foreign->id, 999999] as $id) {
            $this->actingAs($ana)->post("/empresa/contas/convites/{$id}/reenviar")->assertNotFound();
            $this->actingAs($ana)->delete("/empresa/contas/convites/{$id}")->assertNotFound();
        }
        $this->assertTrue($foreign->fresh()->isPending());

        foreach ([$this->member($acme, CompanyRole::SectorManager), $this->member($acme, CompanyRole::Member), User::factory()->create()] as $who) {
            $this->actingAs($who)->get(route('company.accounts'))->assertNotFound();
            $this->actingAs($who)->post(route('company.invites.store'), ['email' => 'x@acme.com', 'role' => 'member'])->assertNotFound();
            $this->actingAs($who)->delete(route('company.invites.cancel', $mine))->assertNotFound();
        }
        $this->assertTrue($mine->fresh()->isPending());

        $acme->forceFill(['suspended_at' => now()])->save();
        $this->actingAs($ana)->delete(route('company.invites.cancel', $mine))->assertRedirect(route('login'));
        $acme->forceFill(['suspended_at' => null])->save();
        $ana->companyMembership->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($ana)->post(route('company.invites.store'), ['email' => 'x@acme.com', 'role' => 'member'])->assertRedirect(route('login'));
        $this->assertTrue($mine->fresh()->isPending());
        $this->assertSame(1, CompanyInvite::query()->where('company_id', $acme->id)->count());

        $ana->companyMembership->forceFill(['deactivated_at' => null])->save();
        app(CompanyInvites::class)->cancel($mine);
        $this->actingAs($ana)->post(route('company.invites.resend', $mine))->assertNotFound();
        foreach (['company_id' => $outra->id, 'role' => 'admin'] as $field => $value) {
            $this->actingAs($ana)->post(route('company.invites.store'), ['email' => "y{$field}@acme.com", 'role' => 'member', $field => $value]);
        }
        $this->assertSame(0, CompanyInvite::query()->where('company_id', $outra->id)->where('email', 'like', 'y%')->count());
        $this->assertSame(0, CompanyInvite::query()->where('role', 'admin')->count());
    }

    // Evidência (abuso) — convidar e cancelar em ciclo manda e-mail do servidor do Security Monitor para qualquer endereço,
    // com o nome da admin (que ela mesma edita) no corpo: sem teto por dia, só o limite por minuto.
    public function test_evidencia_ciclo_convidar_cancelar_sem_teto_diario(): void
    {
        [, $ana] = $this->company('Acme', 'ana@acme.com');
        $ana->forceFill(['name' => 'Ganhe R$ 5.000 hoje em golpe.example'])->save();

        foreach (range(1, 60) as $i) {
            $this->travel(4)->seconds();
            $this->actingAs($ana)->post(route('company.invites.store'), ['email' => "alvo{$i}@terceiro.example", 'role' => 'member']);
            $invite = CompanyInvite::query()->open()->latest('id')->first();
            $invite && $this->actingAs($ana)->delete(route('company.invites.cancel', $invite));
        }

        $sent = Queue::pushed(SendEmail::class)->filter(fn ($job) => $job->mail instanceof CompanyInviteLink)->count();
        fwrite(STDERR, "\n[H-abuso] convites enviados em 4 min: {$sent}\n");
        $this->assertGreaterThan(30, $sent);
    }
}
