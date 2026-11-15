<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\Sector;
use App\Models\User;
use App\Services\Github\GithubInterview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team do delta final da E2d2 (b45a3e2): o duplo clique do passo 1 não reaproveita o que não é da própria conta. */
class RedTeamE2d2DeltaTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private Sector $ti;

    private User $bruno;

    private User $carla;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->joinSector($this->carla, $this->ti, manager: true);
    }

    // Evidência — dois responsáveis do mesmo setor com a mesma resposta no mesmo instante: cada um ganha o seu (o
    // rascunho é só de quem criou); o concluído e o de outro setor nunca são reaproveitados.
    public function test_evidencia_o_segundo_envio_so_cai_no_proprio_rascunho(): void
    {
        $first = app(GithubInterview::class)->startWith($this->bruno, $this->ti->id, ['orgs' => ['acme']]);
        $other = app(GithubInterview::class)->startWith($this->carla, $this->ti->id, ['orgs' => ['acme']]);
        $this->assertNotSame($first->id, $other->id);
        $this->assertSame($this->carla->id, $other->user_id);

        $again = app(GithubInterview::class)->startWith($this->bruno, $this->ti->id, ['orgs' => ['acme']]);
        $this->assertSame($first->id, $again->id);

        $first->forceFill(['completed_at' => now()])->save();
        $this->assertNotSame($first->id, app(GithubInterview::class)->startWith($this->bruno, $this->ti->id, ['orgs' => ['acme']])->id);
        $this->assertSame(3, GithubMonitor::query()->where('company_id', $this->acme->id)->count());
    }
}
