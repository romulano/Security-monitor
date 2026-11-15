<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyAudit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** E2a: os setores (R3, D2, D3): criar, renomear, contas, responsável, auditoria e o isolamento entre empresas. */
class SectorsTest extends TestCase
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

    private function add(Sector $sector, CompanyMember $member, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->ana)->post(route('company.sectors.members.add', $sector), ['member' => $member->id]);
    }

    private function rows(Sector $sector): array
    {
        return DB::table('sector_user')->where('sector_id', $sector->id)->orderBy('user_id')->pluck('is_manager', 'user_id')->all();
    }

    public function test_the_admin_creates_renames_and_fills_a_sector(): void
    {
        $bia = $this->member($this->acme, CompanyRole::SectorManager);
        $caio = $this->member($this->acme);

        $this->actingAs($this->ana)->get(route('company.sectors'))->assertOk()->assertSee('0 de 10 setores')->assertSee('ainda não tem setores');
        $ti = $this->sector('  Tecnologia   da Informação ');
        $this->assertSame('Tecnologia da Informação', $ti->name);

        $this->actingAs($this->ana)->put(route('company.sectors.rename', $ti), ['name' => 'TI'])->assertSessionHas('status', 'O setor agora se chama TI.');
        $this->add($ti, $bia)->assertSessionHas('status');
        $this->add($ti, $caio)->assertSessionHas('status');
        $this->add($ti, $caio)->assertSessionHas('error', "{$caio->user->name} já está no setor TI.");

        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $bia]), ['manager' => 1])->assertSessionHas('status');
        $this->assertSame([$bia->user_id => true, $caio->user_id => false], $this->rows($ti));
        $this->assertSame(['TI'], $bia->user->managedSectors()->pluck('name')->all());

        // A Dani ainda fora do setor: é ela quem aparece no seletor de adicionar.
        $dani = $this->member($this->acme, CompanyRole::SectorManager);
        $this->actingAs($this->ana)->get(route('company.sectors'))->assertOk()
            ->assertSee('1 de 10 setores')
            ->assertSee('Responde pelo setor: Admin Acme (admin da empresa, responde por todos) e '.e($bia->user->name).'.', false)
            ->assertSee('<span aria-current="page" class="font-semibold text-white">Setores</span>', false)
            ->assertSee('data-sector-member="'.$bia->id.'"', false)
            ->assertSee(route('company.sectors.members.remove', [$ti, $caio]))
            ->assertSee('data-confirm="Tirar '.e($caio->user->name).' do setor TI?', false)
            // Designer, rodada 7: o responsável explicado no topo, o membro sabe por que não tem o botão, e o seletor
            // começa vazio e obrigatório (ninguém entra no setor errado com um clique).
            ->assertSee('Para marcar alguém, mude antes o papel da conta para')
            ->assertSee('Para ser responsável, mude o papel em')
            ->assertSee('<option value="" disabled selected>Escolha a conta…</option>', false)
            ->assertSee('Adicionar ao setor');
        // A mesma navegação nas três telas, com a atual marcada.
        $this->actingAs($this->ana)->get(route('company.accounts'))->assertSee('<span aria-current="page" class="font-semibold text-white">Contas</span>', false)
            ->assertSee('href="'.route('company.sectors').'"', false);
        $this->actingAs($this->ana)->get(route('company.activity'))->assertSee('<span aria-current="page" class="font-semibold text-white">Atividade</span>', false);

        // Com dois responsáveis: vírgula e "e" no último.
        $this->add($ti, $dani);
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $dani]), ['manager' => 1]);
        $names = collect([$bia, $dani])->map(fn ($member) => $member->user->name)->sort()->values();
        $this->actingAs($this->ana)->get(route('company.sectors'))
            ->assertSee('Responde pelo setor: Admin Acme (admin da empresa, responde por todos), '.e($names[0]).' e '.e($names[1]).'.', false);
        $this->actingAs($this->ana)->delete(route('company.sectors.members.remove', [$ti, $dani]));

        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $bia]), ['manager' => 0]);
        $this->actingAs($this->ana)->delete(route('company.sectors.members.remove', [$ti, $caio]))->assertSessionHas('status');
        $this->assertSame([$bia->user_id => false], $this->rows($ti));
    }

    public function test_the_limit_of_sectors_is_checked_at_the_edge(): void
    {
        foreach (range(1, 10) as $i) {
            $this->sector("Setor {$i}");
        }

        $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => 'Setor 11'])
            ->assertSessionHas('error', 'A empresa já tem 10 setores, o máximo. Para criar outro, apague um que não use mais. Precisa de mais? Escreva para contato@example.com.');
        $this->assertSame(10, $this->acme->sectors()->count());
        $this->actingAs($this->ana)->get(route('company.sectors'))->assertSee('10 de 10 setores')->assertSee('chegou ao máximo de setores');

        // O limite ajustado pelo admin do Security Monitor vale para a empresa (D2 é o padrão).
        app(Companies::class)->setLimits($this->acme, ['sectors' => 11]);
        $this->sector('Setor 11');
        $this->assertSame(11, $this->acme->sectors()->count());
    }

    public function test_the_name_is_unique_in_the_company_without_case(): void
    {
        $fin = $this->sector('Financeiro');
        $ti = $this->sector('TI');

        // O repetido é erro no campo (Designer, rodada 7): a mensagem embaixo dele, ligada por aria, e o nome digitado volta.
        $this->actingAs($this->ana)->from(route('company.sectors'))->followingRedirects()
            ->post(route('company.sectors.store'), ['name' => 'FINANCEIRO'])
            ->assertSee('<p id="sector-name-error" class="text-sm text-red-300 sm:col-span-2">A empresa já tem o setor Financeiro. Escolha outro nome.</p>', false)
            ->assertSee('aria-invalid="true" aria-describedby="sector-name-error"', false)
            ->assertSee('value="FINANCEIRO"', false);
        $this->actingAs($this->ana)->from(route('company.sectors'))->followingRedirects()
            ->put(route('company.sectors.rename', $ti), ['name' => 'financeiro', '_sector' => $ti->id])
            ->assertSee('aria-describedby="name-'.$ti->id.'-error"', false)
            ->assertSee('value="financeiro"', false)
            ->assertDontSee('id="sector-name-error"', false);
        $this->assertSame('TI', $ti->fresh()->name);

        // Trocar só a caixa do próprio nome pode; nome vazio ou longo, não.
        $this->actingAs($this->ana)->put(route('company.sectors.rename', $fin), ['name' => 'FINANCEIRO'])->assertSessionHas('status');
        $this->assertSame('FINANCEIRO', $fin->fresh()->name);
        $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => '   '])->assertSessionHasErrors('name');
        $this->actingAs($this->ana)->post(route('company.sectors.store'), ['name' => str_repeat('a', 61)])->assertSessionHasErrors('name');

        // Outra empresa usa o mesmo nome à vontade; o banco também barra o repetido, sem o serviço.
        [, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $this->sector('Financeiro', $paulo);
        $this->expectException(QueryException::class);
        DB::table('sectors')->insert(['company_id' => $this->acme->id, 'name' => 'tI', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_only_active_accounts_of_the_company_and_never_the_admin_enter_a_sector(): void
    {
        $ti = $this->sector('TI');
        [$beta, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $stranger = $this->member($beta);
        $off = $this->member($this->acme, state: ['deactivated_at' => now()]);

        $this->add($ti, $stranger)->assertSessionHas('error', 'Escolha uma conta da empresa.');
        $this->add($ti, $off)->assertSessionHas('error', 'Esta conta foi removida da empresa: reative antes de adicionar a um setor.');
        $this->add($ti, $this->ana->companyMembership)->assertSessionHas('error', 'Quem administra a empresa já responde por todos os setores, sem precisar entrar em cada um.');
        $this->assertSame([], $this->rows($ti));

        // A tela não oferece a admin nem a conta desativada, e a admin não aparece como conta de setor (D3).
        $page = $this->actingAs($this->ana)->get(route('company.sectors'))->assertOk();
        $page->assertDontSee('value="'.$this->ana->companyMembership->id.'"', false)->assertDontSee($off->user->email)
            ->assertDontSee('data-sector-member', false);

        // O banco também recusa a conta de outra empresa.
        $this->expectException(QueryException::class);
        DB::table('sector_user')->insert(['sector_id' => $ti->id, 'user_id' => $paulo->id, 'is_manager' => false]);
    }

    public function test_the_manager_needs_the_role_and_follows_it(): void
    {
        $ti = $this->sector('TI');
        $rh = $this->sector('RH');
        $caio = $this->member($this->acme);
        $bia = $this->member($this->acme, CompanyRole::SectorManager);
        $this->add($ti, $caio);

        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $caio]), ['manager' => 1])
            ->assertSessionHas('error', "Para responder pelo setor, {$caio->user->name} precisa do papel \"Responsável do setor\", em Contas.");
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$rh, $bia]), ['manager' => 1])
            ->assertSessionHas('error', "Adicione {$bia->user->name} ao setor RH antes.");

        foreach ([$ti, $rh] as $sector) {
            $this->add($sector, $bia);
            $this->actingAs($this->ana)->put(route('company.sectors.manager', [$sector, $bia]), ['manager' => 1]);
        }
        $this->assertSame(2, $bia->user->managedSectors()->count());

        // De volta a membro: fica nos setores e deixa de responder por eles.
        $this->actingAs($this->ana)->put(route('company.members.role', $bia), ['role' => 'member'])->assertSessionHas('status');
        $this->assertSame(0, $bia->user->managedSectors()->count());
        $this->assertSame(2, $bia->user->sectors()->count());
        $this->assertSame(2, DB::table('company_audit')->where('action', 'sector.manager_unset')->where('target_user_id', $bia->user_id)->count());

        // Passou a administrar: sai dos setores (responde por todos, D3); a antiga admin volta a membro sem setor.
        $this->ana->forceFill(['password' => 'senha-da-admin-1'])->save();
        $this->actingAs($this->ana)->post(route('company.admin.transfer'), ['member' => $bia->id, 'reauth_password' => 'senha-da-admin-1'])
            ->assertSessionHas('status');
        $this->assertSame(CompanyRole::Admin, $bia->fresh()->role);
        $this->assertSame(0, DB::table('sector_user')->where('user_id', $bia->user_id)->count());
        $this->assertSame(0, DB::table('sector_user')->where('user_id', $this->ana->id)->count());
    }

    public function test_member_manager_personal_account_and_other_company_get_404(): void
    {
        $ti = $this->sector('TI');
        $caio = $this->member($this->acme);
        $bia = $this->member($this->acme, CompanyRole::SectorManager);
        $this->add($ti, $caio);
        $this->add($ti, $bia);
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $bia]), ['manager' => 1]);
        [$beta, $paulo] = $this->company('Beta', 'paulo@beta.com');
        $betaSector = $this->sector('Vendas', $paulo);
        $stranger = $this->member($beta);
        $before = DB::table('company_audit')->count();

        $calls = fn (Sector $sector, CompanyMember $member) => [
            ['get', route('company.sectors'), []],
            ['post', route('company.sectors.store'), ['name' => 'Invasão']],
            ['put', route('company.sectors.rename', $sector), ['name' => 'Invasão']],
            ['post', route('company.sectors.members.add', $sector), ['member' => $member->id]],
            ['delete', route('company.sectors.members.remove', [$sector, $member]), []],
            ['put', route('company.sectors.manager', [$sector, $member]), ['manager' => 0]],
        ];

        foreach ([$caio->user, $bia->user, User::factory()->create()] as $who) {
            foreach ($calls($ti, $caio) as [$method, $url, $data]) {
                $this->actingAs($who)->{$method}($url, $data)->assertNotFound();
            }
        }

        // IDOR: a admin da Beta com o setor e as contas da Acme, e a da Acme com a conta da Beta na URL.
        foreach (array_slice($calls($ti, $caio), 2) as [$method, $url, $data]) {
            $this->actingAs($paulo)->{$method}($url, $data)->assertNotFound();
        }
        $this->actingAs($this->ana)->delete(route('company.sectors.members.remove', [$ti, $stranger]))->assertNotFound();
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $stranger]), ['manager' => 1])->assertNotFound();
        $this->actingAs($this->ana)->put(route('company.sectors.rename', $betaSector), ['name' => 'Invasão'])->assertNotFound();
        $this->actingAs($this->ana)->get(route('company.sectors'))->assertDontSee('Vendas');

        $this->assertSame('TI', $ti->fresh()->name);
        $this->assertSame('Vendas', $betaSector->fresh()->name);
        $this->assertSame([$caio->user_id => false, $bia->user_id => true], $this->rows($ti));
        $this->assertSame(1, Sector::query()->where('company_id', $this->acme->id)->count());
        $this->assertSame($before, DB::table('company_audit')->count());

        // Sem a chave da empresa, nem quem administra vê.
        config(['secmon.enterprise.enabled' => false]);
        $this->actingAs($this->ana)->get(route('company.sectors'))->assertNotFound();
    }

    public function test_every_change_goes_to_the_activity_in_portuguese(): void
    {
        $bia = $this->member($this->acme, CompanyRole::SectorManager);
        $ti = $this->sector('TI');
        $this->actingAs($this->ana)->put(route('company.sectors.rename', $ti), ['name' => 'Tecnologia']);
        $this->actingAs($this->ana)->put(route('company.sectors.rename', $ti), ['name' => 'Tecnologia']);
        $this->add($ti, $bia);
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $bia]), ['manager' => 1]);
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $bia]), ['manager' => 1]);
        $this->actingAs($this->ana)->put(route('company.sectors.manager', [$ti, $bia]), ['manager' => 0]);
        $this->actingAs($this->ana)->delete(route('company.sectors.members.remove', [$ti, $bia]));

        $entries = DB::table('company_audit')->where('action', 'like', 'sector.%')->orderBy('id')->get();
        $this->assertSame(['sector.created', 'sector.renamed', 'sector.member_added', 'sector.manager_set', 'sector.manager_unset', 'sector.member_removed'], $entries->pluck('action')->all());
        $this->assertTrue($entries->every(fn ($entry) => $entry->actor_id === $this->ana->id && $entry->company_id === $this->acme->id));
        $this->assertSame(['setor' => 'Tecnologia', 'setor_id' => $ti->id], json_decode($entries[2]->data, true));

        $name = e($bia->user->name);
        $this->actingAs($this->ana)->get(route('company.activity'))->assertOk()
            ->assertSee('Setor criado: TI')
            ->assertSee('Setor renomeado: TI → Tecnologia')
            ->assertSee("{$name} entrou no setor Tecnologia", false)
            ->assertSee("{$name} passou a responder pelo setor Tecnologia", false)
            ->assertSee("{$name} deixou de responder pelo setor Tecnologia", false)
            ->assertSee("{$name} saiu do setor Tecnologia", false);
        foreach (['sector.created', 'sector.renamed', 'sector.member_added', 'sector.member_removed', 'sector.manager_set', 'sector.manager_unset'] as $action) {
            $this->assertArrayHasKey($action, CompanyAudit::ACTIONS);
        }
    }
}
