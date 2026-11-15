<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyRemoval;
use App\Services\Company\CompanyRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Red Team da E2a2 (contas nos setores e responsável). Sem achado novo: as evidências do que resistiu. */
class RedTeamE2a2Test extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    protected function setUp(): void
    {
        parent::setUp();
        config(['secmon.enterprise.enabled' => true]);
        Queue::fake();
        [$this->acme, $this->ana] = $this->company('Acme', 'ana@acme.com');
        $this->ti = $this->sector('TI');
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

    private function member(Company $company, CompanyRole $role = CompanyRole::Member, array $state = []): CompanyMember
    {
        $user = User::factory()->create();

        return $company->members()->forceCreate($state + ['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
    }

    private function sector(string $name, ?User $admin = null): Sector
    {
        $this->actingAs($admin ?? $this->ana)->post(route('company.sectors.store'), ['name' => $name])->assertRedirect(route('company.sectors'));

        return Sector::query()->orderByDesc('id')->firstOrFail();
    }

    private function rows(): array
    {
        return DB::table('sector_user')->where('sector_id', $this->ti->id)->orderBy('user_id')->pluck('is_manager', 'user_id')->all();
    }

    // Evidência — mass assignment: is_manager, user_id e sector_id no corpo não valem; o ator da auditoria é quem pede.
    public function test_evidencia_corpo_forjado_e_ator_da_auditoria(): void
    {
        $bia = $this->member($this->acme, CompanyRole::SectorManager);
        $caio = $this->member($this->acme, CompanyRole::SectorManager);
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');

        $this->actingAs($this->ana)->post(route('company.sectors.members.add', $this->ti), [
            'member' => $bia->id, 'is_manager' => 1, 'user_id' => $paulo->id, 'sector_id' => 999, 'actor_id' => $paulo->id,
        ])->assertSessionHas('status');
        $this->assertSame([$bia->user_id => false], $this->rows());

        // A conta da URL manda: `member` no corpo não troca o alvo.
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$this->ti, $bia]), ['manager' => 1, 'member' => $caio->id]);
        $this->assertSame([$bia->user_id => true], $this->rows());

        $entries = DB::table('company_audit')->where('action', 'like', 'sector.member%')->orWhere('action', 'like', 'sector.manager%')->get();
        $this->assertTrue($entries->every(fn ($e) => $e->actor_id === $this->ana->id && $e->target_user_id === $bia->user_id));
        $this->assertTrue($entries->every(fn ($e) => array_keys(json_decode($e->data, true)) === ['setor', 'setor_id']), 'Dado pessoal no data.');
    }

    // Evidência — o papel acompanha os setores: membro→responsável não ganha marca; responsável→membro perde;
    // a antiga admin vira membro sem setor e leva 404 na requisição seguinte; a nova sai dos setores (D3).
    public function test_evidencia_papel_e_setores_andam_juntos(): void
    {
        $bia = $this->member($this->acme);
        $this->actingAs($this->ana)->post(route('company.sectors.members.add', $this->ti), ['member' => $bia->id]);

        app(CompanyRoles::class)->change($bia, CompanyRole::SectorManager, $this->ana);
        $this->assertSame([$bia->user_id => false], $this->rows());
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$this->ti, $bia]), ['manager' => 1]);
        $this->assertSame([$bia->user_id => true], $this->rows());

        app(CompanyRoles::class)->change($bia->fresh(), CompanyRole::Member, $this->ana);
        $this->assertSame([$bia->user_id => false], $this->rows());
        app(CompanyRoles::class)->change($bia->fresh(), CompanyRole::SectorManager, $this->ana);
        $this->assertSame([$bia->user_id => false], $this->rows(), 'A marca voltou sozinha.');

        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$this->ti, $bia]), ['manager' => 1]);
        app(CompanyRoles::class)->transfer($this->ana, $bia->id);
        $this->assertSame([], $this->rows());

        $ana = $this->ana->fresh();
        $this->actingAs($ana)->get(route('company.sectors'))->assertNotFound();
        $this->actingAs($ana)->post(route('company.sectors.store'), ['name' => 'Volta'])->assertNotFound();
        $this->assertSame(0, DB::table('sector_user')->where('user_id', $ana->id)->count());
        $this->actingAs($bia->user->fresh())->post(route('company.sectors.members.add', $this->ti), ['member' => $ana->companyMembership->id])
            ->assertSessionHas('status');
        $this->assertSame([$ana->id => false], $this->rows());
    }

    // Evidência — desativada: fica no setor com a marca (volta igual se reativada), não entra em outro, não é marcada,
    // some da tela; a poda dos 30 dias tira a conta dos setores e o setor fica.
    public function test_evidencia_desativada_reativada_e_poda(): void
    {
        $bia = $this->member($this->acme, CompanyRole::SectorManager);
        $rh = $this->sector('RH');
        $this->actingAs($this->ana)->post(route('company.sectors.members.add', $this->ti), ['member' => $bia->id]);
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$this->ti, $bia]), ['manager' => 1]);

        app(CompanyRemoval::class)->deactivate($bia, $this->ana);
        $this->assertSame([$bia->user_id => true], $this->rows());
        $this->actingAs($this->ana)->post(route('company.sectors.members.add', $rh), ['member' => $bia->id])->assertSessionHas('error');
        $this->actingAs($this->ana)->get(route('company.sectors'))->assertDontSee($bia->user->email);

        app(CompanyRemoval::class)->reactivate($bia->fresh(), $this->ana);
        $this->assertSame([$bia->user_id => true], $this->rows());

        app(CompanyRemoval::class)->deactivate($bia->fresh(), $this->ana);
        DB::table('company_user')->where('id', $bia->id)->update(['deactivated_at' => now()->subDays(31)]);
        app(CompanyRemoval::class)->purge();
        $this->assertFalse(User::query()->whereKey($bia->user_id)->exists());
        $this->assertSame([], $this->rows());
        $this->assertSame(2, $this->acme->sectors()->count());
    }

    // Evidência — 404 antes do throttle nas rotas novas (membro e responsável marcado nunca levam 429).
    public function test_evidencia_404_antes_do_throttle_nas_contas_do_setor(): void
    {
        $bia = $this->member($this->acme, CompanyRole::SectorManager);
        $this->actingAs($this->ana)->post(route('company.sectors.members.add', $this->ti), ['member' => $bia->id]);
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$this->ti, $bia]), ['manager' => 1]);
        $caio = $this->member($this->acme);

        foreach (range(1, 62) as $i) {
            $this->actingAs($bia->user)->post(route('company.sectors.members.add', $this->ti), ['member' => $caio->id])->assertNotFound();
            $this->actingAs($bia->user)->put(route('company.sectors.manager', [$this->ti, $bia]), ['manager' => 0])->assertNotFound();
        }
        $this->assertSame([$bia->user_id => true], $this->rows());
    }
}
