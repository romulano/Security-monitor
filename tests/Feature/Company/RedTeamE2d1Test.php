<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\LookalikeAlert;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\ProfileIndex;
use App\Services\Lookalike\LookalikeAlertRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Alerts\FakeProfileIndex;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2d1 (o Radar pela visibilidade). Sem achado que bloqueie: as evidências e dois pontos para depois. */
class RedTeamE2d1Test extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private User $bruno;

    private Sector $ti;

    private Sector $rh;

    private LookalikeWatch $watch;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->watch = $this->watchOf($this->bruno, $this->acme, $this->ti);
        $this->watch->forceFill(['status' => 'active', 'completed_at' => now(), 'step_reached' => 3, 'scan_status' => 'done'])->save();
    }

    private function move(User $who, array $query, $sector)
    {
        return $this->actingAs($who)->put(route('alerts.lookalikes.sector', $query), ['setor' => $sector]);
    }

    // Evidência — trocar o setor: setor de outra empresa, sem o `?radar=` (o "primeiro" de cada conta), id que não existe
    // e a troca para o mesmo setor: nada muda e nada entra na Atividade.
    public function test_evidencia_trocar_setor_nas_bordas(): void
    {
        [$beta] = $this->activeCompany('Beta', 'paulo@beta.com');
        $vendas = $this->companySector($beta, 'Vendas');

        $this->move($this->ana, ['radar' => $this->watch->id], $vendas->id)->assertSessionHas('error');
        $this->move($this->ana, [], $this->rh->id)->assertNotFound();
        $this->move($this->ana, ['radar' => $this->watch->id], 999999)->assertSessionHas('error');
        $this->move($this->bruno, ['radar' => $this->watch->id], $this->ti->id)->assertRedirect();

        $this->assertSame($this->ti->id, $this->watch->fresh()->sector_id);
        $this->assertSame(0, DB::table('company_audit')->where('action', 'item.sector_changed')->count());
    }

    // Depois (E5b; aviso no diálogo de trocar): a admin leva o Radar do Bruno para o RH, em que ele não está. Até a E5b o
    // envio vai para os canais de quem criou, e quem criou já não vê: o Radar fica mudo, sem ninguém avisado.
    // Corrigido na E5b1: o Radar movido para o RH avisa nos canais do RH, mesmo com quem o criou fora do RH (o "Radar
    // mudo" acabou); a DM de quem criou não recebe.
    public function test_evidencia_radar_movido_avisa_no_canal_do_setor_de_destino(): void
    {
        $personal = NotificationChannel::query()->create(['user_id' => $this->bruno->id, 'type' => 'discord', 'method' => 'dm', 'status' => 'active', 'realtime' => true, 'digest' => false, 'timezone' => 'America/Sao_Paulo']);
        $rh = $this->companyChannel($this->ana, $this->acme, $this->rh);
        $lookalike = $this->watch->lookalikes()->create(['host' => 'acme-login.com', 'first_seen_at' => now(), 'risk' => 'high']);

        $this->move($this->ana, ['radar' => $this->watch->id], $this->rh->id)->assertSessionHas('status');
        app(LookalikeAlertRouter::class)->route($alert = LookalikeAlert::query()->create(['lookalike_id' => $lookalike->id, 'kind' => 'new', 'risk' => 'critical']));

        $this->assertSame('queued', $alert->fresh()->status);
        $this->assertSame([$rh->id], DB::table('notification_deliveries')->pluck('channel_id')->all());
        $this->assertNotContains($personal->id, DB::table('notification_deliveries')->pluck('channel_id')->all());
    }

    // Corrigido na E3a2: o "já existe um Radar para X" olha a empresa toda. O responsável do RH não cria um segundo Radar
    // do mesmo domínio que o TI já vigia, e a mensagem não diz de qual setor ele é.
    public function test_evidencia_radar_repetido_do_mesmo_dominio_em_outro_setor(): void
    {
        $carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($carla, $this->rh, manager: true);
        $domain = $this->watch->domain->domain;

        $this->actingAs($carla)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => $domain, 'setor' => $this->rh->id])
            ->assertSessionHasErrors(['outro' => "Já existe um Radar para {$domain} na empresa."]);

        $this->assertSame(1, LookalikeWatch::query()->where('company_id', $this->acme->id)->whereHas('domain', fn ($q) => $q->where('domain', $domain))->count());
    }
}
