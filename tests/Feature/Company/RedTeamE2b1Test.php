<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\User;
use App\Services\Company\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2b1 (Visibility, Viewer, scope visibleTo, stampFor). Sem achado novo: as evidências e as bordas. */
class RedTeamE2b1Test extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
    }

    private function v(): Visibility
    {
        return app(Visibility::class);
    }

    /** @return list<int> os perfis que a conta vê */
    private function seen(User $user): array
    {
        return AlertProfile::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
    }

    // Evidência — as bordas da regra: dois setores, setor apagado, saiu do setor, marca sem papel, admin que virou membro,
    // e a empresa parada (chave global, chave da empresa, suspensa) dá "nada", nunca "pessoal".
    public function test_evidencia_bordas_da_regra(): void
    {
        [$ti, $rh, $fin] = [$this->companySector($this->acme, 'TI'), $this->companySector($this->acme, 'RH'), $this->companySector($this->acme, 'Fin')];
        $caio = $this->companyAccount($this->acme);
        $this->joinSector($caio, $ti);
        $this->joinSector($caio, $rh);
        $bia = $this->companyAccount($this->acme);
        $this->joinSector($bia, $fin, manager: true); // a marca sem o papel
        $all = $this->profileOf($this->ana, $this->acme);
        $pti = $this->profileOf($this->ana, $this->acme, $ti);
        $prh = $this->profileOf($this->ana, $this->acme, $rh);
        $pfin = $this->profileOf($this->ana, $this->acme, $fin);

        $this->assertSame([$all->id, $pti->id, $prh->id], $this->seen($caio));
        $this->assertSame([$all->id, $pfin->id], $this->seen($bia));
        $this->assertFalse($this->v()->manages($bia, $pfin), 'Marca sem o papel configura.');
        $this->assertSame([], $this->v()->sectorChoices($bia)->all());

        DB::table('sectors')->where('id', $rh->id)->delete();
        $this->assertTrue($this->v()->sees($bia, $prh->fresh()), 'O item do setor apagado é da empresa toda (R4).');
        $ti->users()->detach($caio->id);
        $this->assertFalse($this->v()->sees($caio, $pti), 'Saiu do setor e continua vendo.');

        // A admin que passou a administração vira membro sem setor: só o sem setor (e o do setor apagado).
        $this->memberRow($this->ana)->forceFill(['role' => CompanyRole::Member])->save();
        $this->memberRow($caio)->forceFill(['role' => CompanyRole::Admin])->save();
        $this->refreshVisibility();
        $this->assertSame([$all->id, $prh->id], $this->seen($this->ana));

        foreach ([
            fn () => config(['secmon.enterprise.enabled' => false]),
            fn () => $this->acme->forceFill(['enterprise_enabled_at' => null])->save(),
            fn () => $this->acme->forceFill(['suspended_at' => now()])->save(),
        ] as $i => $stop) {
            config(['secmon.enterprise.enabled' => true]);
            $this->acme->forceFill(['enterprise_enabled_at' => now(), 'suspended_at' => null])->save();
            $stop();
            $this->refreshVisibility();
            $this->assertSame([], $this->seen($caio), "Caso {$i}: admin vê com a empresa parada.");
            $this->assertSame('nothing', $this->v()->viewer($caio)->kind, "Caso {$i}");
            $this->assertFalse($this->v()->manages($caio, $all));
        }
    }

    // Evidência (para a E2c1/E2c2 e a E2d): o item de conta de empresa sem carimbo, como o código no ar ainda grava
    // (company_id nulo), some para todo mundo, até para quem criou. Ler pelo scope antes de carimbar na criação, ou sem
    // carimbar o que já existe, esconde o item do próprio criador.
    public function test_evidencia_item_sem_carimbo_de_conta_de_empresa_some_ate_para_o_criador(): void
    {
        $caio = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $profile = AlertProfile::query()->create(['user_id' => $caio->id, 'status' => 'draft']);

        $this->assertSame([], $this->seen($caio));
        $this->assertSame([], $this->seen($this->ana));
        $this->assertFalse($this->v()->sees($caio, $profile));
        $this->assertSame(1, $caio->alertProfiles()->count(), 'A relação de quem criou ainda o acha (limites, poda).');
    }

    // Evidência — o domínio sem raiz (cópia do webhook ou dos testes) não aparece para ninguém, nem para a conta pessoal
    // dona dele: quando a E2e passar o webhook pelo scope, ele precisa de raiz própria (o canal, na E5a).
    public function test_evidencia_dominio_sem_raiz_some_ate_para_o_dono_pessoal(): void
    {
        $paulo = User::factory()->create();
        $copy = MonitoredDomain::query()->create(['user_id' => $paulo->id, 'domain' => 'paulo.com.br', 'verify_token' => Str::random(32)]);

        $this->assertFalse($this->v()->sees($paulo, $copy));
        $this->assertSame(0, MonitoredDomain::query()->visibleTo($paulo)->count());
    }

    // Evidência — stampFor: o responsável não usa setor em que só é membro nem "sem setor"; a admin, nenhum de outra
    // empresa; a conta desativada e a pessoal com setor, nada. O custo: quantas consultas cada chamada faz.
    public function test_evidencia_stamp_for_e_custo_por_chamada(): void
    {
        [$beta] = $this->activeCompany('Beta', 'paulo@beta.com');
        $vendas = $this->companySector($beta, 'Vendas');
        [$ti, $rh] = [$this->companySector($this->acme, 'TI'), $this->companySector($this->acme, 'RH')];
        $bia = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($bia, $ti, manager: true);
        $this->joinSector($bia, $rh);
        $off = $this->companyAccount($this->acme, CompanyRole::SectorManager, ['deactivated_at' => now()]);
        $this->joinSector($off, $ti, manager: true);

        foreach ([[$bia, $rh->id], [$bia, null], [$this->ana, $vendas->id], [$off, $ti->id], [User::factory()->create(), $ti->id]] as [$who, $sector]) {
            try {
                $this->v()->stampFor($who, $sector);
                $this->fail('stampFor deixou: '.$who->id.' no setor '.var_export($sector, true));
            } catch (ActionRefused) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['user_id' => $bia->id, 'company_id' => $this->acme->id, 'sector_id' => $ti->id], $this->v()->stampFor($bia, $ti->id));

        $item = $this->monitorOf($this->ana, $this->acme, $ti);
        $count = function (callable $call): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $call();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        // O Viewer (a conta e a empresa) é lido uma vez por requisição: a primeira chamada custa 3 consultas, e cada
        // item seguinte de uma lista custa 1 (era 5 por item com `manages`, achado do Red Team).
        $this->refreshVisibility();
        $this->assertSame(3, $count(fn () => $this->v()->sees($bia, $item)));
        $this->assertSame(1, $count(fn () => $this->v()->manages($bia, $item)));
        $this->assertSame(1, $count(fn () => GithubMonitor::query()->visibleTo($bia)->get()));
        $this->assertSame(1, $count(fn () => LookalikeWatch::query()->visibleTo($bia)->get()));
    }
}
