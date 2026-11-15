<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E2b (R4, R10, R14, §4.5; DESENHO-5.2 §4): quem vê e quem configura cada raiz e cada filho, para a conta pessoal, a
 * admin, o responsável, o membro, a conta desativada, a empresa suspensa ou desligada e outra empresa.
 */
class VisibilityTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private const ROOTS = ['profileOf', 'watchOf', 'monitorOf'];

    private Company $acme;

    private Company $beta;

    private Sector $ti;

    private Sector $fin;

    /** @var array<string, User> */
    private array $who = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        [$this->beta, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->fin = $this->companySector($this->acme, 'Financeiro');

        $this->who = [
            'ana' => $ana,
            'bia' => $this->companyAccount($this->acme, CompanyRole::SectorManager),
            'caio' => $this->companyAccount($this->acme),
            'dani' => $this->companyAccount($this->acme),
            'off' => $this->companyAccount($this->acme, state: ['deactivated_at' => now()]),
            'paulo' => $paulo,
            'pedro' => User::factory()->create(),
        ];
        $this->joinSector($this->who['bia'], $this->ti, manager: true);
        $this->joinSector($this->who['caio'], $this->ti);
        $this->joinSector($this->who['off'], $this->ti);
    }

    private function visibility(): Visibility
    {
        return app(Visibility::class);
    }

    /** Um item de cada lugar, da raiz pedida: pessoal, Acme sem setor, Acme TI, Acme Financeiro, Beta. @return array<string, Model> */
    private function items(string $root): array
    {
        return [
            'pessoal' => $this->{$root}($this->who['pedro']),
            'acme' => $this->{$root}($this->who['ana'], $this->acme),
            'ti' => $this->{$root}($this->who['bia'], $this->acme, $this->ti),
            'fin' => $this->{$root}($this->who['ana'], $this->acme, $this->fin),
            'beta' => $this->{$root}($this->who['paulo'], $this->beta),
        ];
    }

    /** @param array<string, Model> $items @return list<string> */
    private function seen(string $who, array $items): array
    {
        $model = reset($items);
        $ids = $model::query()->visibleTo($this->who[$who])->pluck('id')->all();

        return array_keys(array_filter($items, fn (Model $item) => in_array($item->id, $ids, true)));
    }

    public function test_each_account_sees_exactly_its_part_of_every_root(): void
    {
        foreach (self::ROOTS as $root) {
            $items = $this->items($root);

            $this->assertSame(['pessoal'], $this->seen('pedro', $items), $root);
            $this->assertSame(['acme', 'ti', 'fin'], $this->seen('ana', $items), $root);
            $this->assertSame(['acme', 'ti'], $this->seen('bia', $items), $root);
            $this->assertSame(['acme', 'ti'], $this->seen('caio', $items), $root);
            // Sem setor nenhum: só o que é da empresa toda.
            $this->assertSame(['acme'], $this->seen('dani', $items), $root);
            $this->assertSame([], $this->seen('off', $items), $root);
            $this->assertSame(['beta'], $this->seen('paulo', $items), $root);

            // sees() dá o mesmo que o scope.
            foreach ($items as $where => $item) {
                $this->assertSame(in_array($where, ['acme', 'ti'], true), $this->visibility()->sees($this->who['caio'], $item), "{$root} {$where}");
            }
        }
    }

    public function test_children_follow_their_root_in_the_scope_and_in_sees(): void
    {
        $ti = $this->childrenOf($this->profileOf($this->who['bia'], $this->acme, $this->ti), $this->watchOf($this->who['bia'], $this->acme, $this->ti), $this->monitorOf($this->who['bia'], $this->acme, $this->ti));
        $fin = $this->childrenOf($this->profileOf($this->who['ana'], $this->acme, $this->fin), $this->watchOf($this->who['ana'], $this->acme, $this->fin), $this->monitorOf($this->who['ana'], $this->acme, $this->fin));
        $mine = $this->childrenOf($this->profileOf($this->who['pedro']), $this->watchOf($this->who['pedro']), $this->monitorOf($this->who['pedro']));

        foreach ($ti as $class => $item) {
            $caio = $class::query()->visibleTo($this->who['caio'])->pluck('id');
            $this->assertTrue($caio->contains($item->id), $class);
            $this->assertFalse($caio->contains($fin[$class]->id), $class);
            $this->assertFalse($caio->contains($mine[$class]->id), $class);
            $this->assertTrue($this->visibility()->sees($this->who['caio'], $item), $class);
            $this->assertFalse($this->visibility()->sees($this->who['caio'], $fin[$class]), $class);
            $this->assertFalse($this->visibility()->sees($this->who['paulo'], $item), $class);

            $this->assertTrue($this->visibility()->sees($this->who['ana'], $fin[$class]), $class);
            $this->assertTrue($this->visibility()->sees($this->who['pedro'], $mine[$class]), $class);
            $this->assertFalse($this->visibility()->sees($this->who['pedro'], $item), $class);
        }
    }

    public function test_only_the_admin_and_the_sector_manager_configure_and_no_sector_is_the_admins(): void
    {
        foreach (self::ROOTS as $root) {
            $items = $this->items($root);
            $manages = fn (string $who) => array_keys(array_filter($items, fn (Model $item) => $this->visibility()->manages($this->who[$who], $item)));

            $this->assertSame(['pessoal'], $manages('pedro'), $root);
            $this->assertSame(['acme', 'ti', 'fin'], $manages('ana'), $root);
            // O responsável configura o setor dele; o que é da empresa toda, não (P1).
            $this->assertSame(['ti'], $manages('bia'), $root);
            $this->assertSame([], $manages('caio'), $root);
            $this->assertSame([], $manages('off'), $root);
            $this->assertSame(['beta'], $manages('paulo'), $root);
        }

        // Responsável marcado sem estar no setor certo não configura o outro.
        $this->joinSector($this->who['bia'], $this->fin);
        $this->assertFalse($this->visibility()->manages($this->who['bia'], $this->profileOf($this->who['ana'], $this->acme, $this->fin)));
    }

    public function test_the_manager_mark_counts_only_with_the_role_and_an_active_account(): void
    {
        $ti = $this->profileOf($this->who['bia'], $this->acme, $this->ti);
        $this->assertTrue($this->visibility()->manages($this->who['bia'], $ti));

        // A marca sem o papel (o banco não amarra os dois): vê, não configura nem cria.
        $this->ti->users()->updateExistingPivot($this->who['caio']->id, ['is_manager' => true]);
        $this->assertTrue($this->visibility()->sees($this->who['caio'], $ti));
        $this->assertFalse($this->visibility()->manages($this->who['caio'], $ti));
        $this->assertSame([], $this->visibility()->sectorChoices($this->who['caio'])->all());

        // O papel trocado para membro tira o poder na hora, mesmo com a marca.
        $this->memberRow($this->who['bia'])->forceFill(['role' => CompanyRole::Member])->save();
        $this->refreshVisibility();
        $this->assertFalse($this->visibility()->manages($this->who['bia'], $ti));
        $this->expectException(ActionRefused::class);
        $this->visibility()->stampFor($this->who['bia'], $this->ti->id);
    }

    public function test_a_deactivated_manager_keeps_the_mark_but_loses_everything(): void
    {
        $ti = $this->profileOf($this->who['bia'], $this->acme, $this->ti);
        $this->memberRow($this->who['bia'])->forceFill(['deactivated_at' => now()])->save();

        $this->assertFalse($this->visibility()->sees($this->who['bia'], $ti));
        $this->assertFalse($this->visibility()->manages($this->who['bia'], $ti));
        $this->assertSame([], $this->visibility()->sectorChoices($this->who['bia'])->all());
    }

    public function test_access_ends_at_once_when_the_account_leaves_or_the_company_stops(): void
    {
        $ti = $this->profileOf($this->who['bia'], $this->acme, $this->ti);
        $acme = $this->profileOf($this->who['ana'], $this->acme);
        $mine = $this->profileOf($this->who['pedro']);

        // Saiu do setor: deixa de ver na próxima consulta (o setor é subconsulta; só quem a conta é fica guardado na requisição).
        $this->ti->users()->detach($this->who['caio']->id);
        $this->assertFalse($this->visibility()->sees($this->who['caio'], $ti));
        $this->assertTrue($this->visibility()->sees($this->who['caio'], $acme));

        // Empresa suspensa, ou com a chave dela ou a global desligada: ninguém dela vê nada; a conta pessoal segue.
        app(Companies::class)->suspend($this->acme);
        $this->refreshVisibility();
        $this->assertFalse($this->visibility()->sees($this->who['ana'], $acme));
        app(Companies::class)->resume($this->acme);
        $this->refreshVisibility();
        $this->assertTrue($this->visibility()->sees($this->who['ana'], $acme));

        app(Companies::class)->disable($this->acme);
        $this->refreshVisibility();
        $this->assertFalse($this->visibility()->sees($this->who['ana'], $acme));
        app(Companies::class)->enable($this->acme);

        config(['secmon.enterprise.enabled' => false]);
        $this->refreshVisibility();
        $this->assertSame([], AlertProfile::query()->visibleTo($this->who['ana'])->pluck('id')->all());
        $this->assertSame([$mine->id], AlertProfile::query()->visibleTo($this->who['pedro'])->pluck('id')->all());
    }

    public function test_the_personal_account_never_has_nor_sees_a_company_row(): void
    {
        // Domínio sem raiz (a cópia da posse do webhook ou dos testes): nenhuma tela de monitoramento o mostra.
        $copy = MonitoredDomain::query()->create(['user_id' => $this->who['pedro']->id, 'domain' => 'copia.com.br', 'verify_token' => Str::random(32)]);
        $this->assertFalse($this->visibility()->sees($this->who['pedro'], $copy));

        // Os itens da empresa não aparecem para a conta pessoal (o `company_id is null` do scope).
        $this->profileOf($this->who['ana'], $this->acme);
        $this->assertSame([], AlertProfile::query()->visibleTo($this->who['pedro'])->pluck('id')->all());

        // E a linha forjada (conta pessoal com company_id) nem existe: o banco recusa (N1 do Red Team da E2b0).
        $this->expectException(QueryException::class);
        $this->profileOf($this->who['pedro'], $this->acme);
    }

    public function test_stamp_for_says_who_creates_where(): void
    {
        $stamp = fn (string $who, ?Sector $sector) => $this->visibility()->stampFor($this->who[$who], $sector?->id);

        $this->assertSame(['user_id' => $this->who['pedro']->id, 'company_id' => null, 'sector_id' => null], $stamp('pedro', null));
        $this->assertSame(['user_id' => $this->who['ana']->id, 'company_id' => $this->acme->id, 'sector_id' => null], $stamp('ana', null));
        $this->assertSame($this->ti->id, $stamp('ana', $this->ti)['sector_id']);
        $this->assertSame(['user_id' => $this->who['bia']->id, 'company_id' => $this->acme->id, 'sector_id' => $this->ti->id], $stamp('bia', $this->ti));

        $beta = $this->companySector($this->beta, 'Vendas');
        foreach ([['pedro', $this->ti], ['ana', $beta], ['bia', null], ['bia', $this->fin], ['caio', $this->ti], ['dani', null], ['off', $this->ti], ['paulo', $this->ti]] as [$who, $sector]) {
            try {
                $stamp($who, $sector);
                $this->fail("{$who} criou em ".($sector?->name ?? 'sem setor'));
            } catch (ActionRefused) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(['Financeiro', 'TI'], $this->visibility()->sectorChoices($this->who['ana'])->pluck('name')->all());
        $this->assertSame(['TI'], $this->visibility()->sectorChoices($this->who['bia'])->pluck('name')->all());
        $this->assertSame([], $this->visibility()->sectorChoices($this->who['caio'])->all());
        $this->assertSame([true, false, false], [$this->visibility()->canUseNoSector($this->who['ana']), $this->visibility()->canUseNoSector($this->who['bia']), $this->visibility()->canUseNoSector($this->who['pedro'])]);
    }

    public function test_company_and_sector_never_come_from_mass_assignment(): void
    {
        $owner = $this->who['pedro'];
        $values = ['user_id' => $owner->id, 'company_id' => $this->acme->id, 'sector_id' => $this->ti->id];

        $profile = AlertProfile::query()->create($values + ['status' => 'draft']);
        $monitor = GithubMonitor::query()->create($values + ['status' => 'draft']);
        $domain = MonitoredDomain::query()->create(['user_id' => $owner->id, 'domain' => 'a.com.br', 'verify_token' => Str::random(32)]);
        $watch = LookalikeWatch::query()->create($values + ['domain_id' => $domain->id]);
        $profile->update(['company_id' => $this->beta->id]);

        foreach ([$profile, $monitor, $watch] as $item) {
            $this->assertSame([$owner->id, null, null], [$item->fresh()->user_id, $item->fresh()->company_id, $item->fresh()->sector_id], $item::class);
        }
    }
}
