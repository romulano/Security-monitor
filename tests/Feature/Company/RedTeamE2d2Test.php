<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\CompanyRemoval;
use App\Services\Company\ItemSectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2d2 (o GitHub pela visibilidade). */
class RedTeamE2d2Test extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private User $bruno;

    private Sector $ti;

    private Sector $rh;

    private GithubMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->joinSector($this->bruno, $this->rh);
        $this->monitor = $this->monitorOf($this->bruno, $this->acme, $this->ti);
        $this->monitor->forceFill(['completed_at' => now(), 'orgs' => ['acme-ti']])->save();
    }

    // T1 — o aviso de "fica mudo" ao trocar o setor ignora quem criou e foi removido: diz que no RH (em que o Bruno
    // está) o monitoramento segue mandando, mas a trava do envio já recusa tudo da conta desativada.
    public function test_rt_t1_o_aviso_de_mudo_conta_o_criador_desativado(): void
    {
        $choices = [$this->ti->id, $this->rh->id];
        $this->assertSame([], app(ItemSectors::class)->mutedIn($this->monitor, $choices));

        app(CompanyRemoval::class)->deactivate($this->memberRow($this->bruno), $this->ana);

        $this->assertSame($choices, app(ItemSectors::class)->mutedIn($this->monitor->fresh(), $choices), 'O aviso não diz que o monitoramento do criador removido fica mudo.');
    }

    // Evidência — a conta pessoal não entra pelo caminho da empresa: `?monitor=` dá 404, trocar o setor dá 404, e o
    // segundo monitoramento pessoal não nasce (um só, o único parcial da E2b0).
    public function test_evidencia_conta_pessoal_fica_com_um_e_sem_setor(): void
    {
        $pedro = User::factory()->create();
        $this->actingAs($pedro)->get(route('alerts.github.interview', ['passo' => 1]))->assertOk();
        $own = GithubMonitor::query()->where('user_id', $pedro->id)->sole();

        $this->actingAs($pedro)->get(route('alerts.github.interview', ['passo' => 1, 'monitor' => $own->id]))->assertNotFound();
        $this->actingAs($pedro)->put(route('alerts.github.interview.update', ['passo' => 1, 'monitor' => $own->id]), ['answers' => ['orgs' => ['x']]])->assertNotFound();
        $this->actingAs($pedro)->put(route('alerts.github.sector', ['monitor' => $own->id]), ['setor' => 'sem'])->assertNotFound();
        $this->actingAs($pedro)->put(route('alerts.github.interview.update', ['passo' => 1]), ['answers' => ['orgs' => ['outra']], 'setor' => $this->ti->id])->assertRedirect();

        $this->assertSame(1, GithubMonitor::query()->where('user_id', $pedro->id)->count());
        $this->assertSame([null, null], [$own->fresh()->company_id, $own->fresh()->sector_id]);
        $this->actingAs($pedro)->get(route('alerts.github', ['monitor' => $this->monitor->id]))->assertNotFound();
    }
}
