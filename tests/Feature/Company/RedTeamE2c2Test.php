<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\InterviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2c2 (Notícias, escrita). */
class RedTeamE2c2Test extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
    }

    // U1 — sem `?alerta=`, o alerta de empresa cai no "primeiro que a conta configura" (o mais antigo): trocar o setor,
    // pausar, apagar e concluir a entrevista agem num alerta que a tela não mostrou (DESENHO-5.2 §4.3).
    public function test_rt_u1_sem_alerta_na_url_nada_acontece_no_alerta_de_empresa(): void
    {
        $older = $this->profileOf($this->ana, $this->acme, $this->ti);
        $this->profileOf($this->ana, $this->acme, $this->rh);

        $this->actingAs($this->ana)->put(route('alerts.sector'), ['setor' => 'sem']);
        $this->actingAs($this->ana)->post(route('alerts.pause'));
        $this->actingAs($this->ana)->post(route('alerts.interview.finish'));
        $this->actingAs($this->ana)->delete(route('alerts.destroy'));

        $older = $older->fresh();
        $this->assertNotNull($older, 'Apagou o alerta mais antigo sem o ?alerta=.');
        $this->assertSame($this->ti->id, $older->sector_id, 'Trocou o setor do alerta mais antigo sem o ?alerta=.');
        $this->assertSame('active', $older->status, 'Pausou o alerta mais antigo sem o ?alerta=.');
        $this->assertSame(2, AlertProfile::query()->where('company_id', $this->acme->id)->count());
    }

    // U2 — o limite fica fora da trava: dois passos 1 diferentes ao mesmo tempo (as duas abas) passam pelo `canCreate`
    // do request e o `startWith` cria o a mais. Desde a E5d o limite é o da empresa (20, ajustável), não o de quem cria.
    public function test_rt_u2_o_limite_da_empresa_vale_dentro_da_trava(): void
    {
        $this->acme->forceFill(['limit_alert_profiles' => 5])->save();
        $bia = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($bia, $this->ti, manager: true);
        $existing = AlertProfile::query()->where('company_id', $this->acme->id)->count();
        foreach (range(1, 5 - $existing) as $i) {
            $this->profileOf($bia, $this->acme, $this->ti);
        }

        try {
            app(InterviewService::class)->startWith($bia, $this->ti->id, ['alert_name' => 'Sexto', 'company_name' => 'Acme']);
        } catch (ActionRefused) {
        }

        $this->assertSame(5, AlertProfile::query()->where('company_id', $this->acme->id)->count(), 'Passou do limite de alertas da empresa.');
    }
}
