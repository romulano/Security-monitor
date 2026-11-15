<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\NewsCase;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2c1 (Notícias e caixa, leitura; `?alerta=` só no que a conta configura). Evidências do que resistiu. */
class RedTeamE2c1Test extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private User $bia;

    private User $caio;

    private Sector $ti;

    private AlertProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->bia = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->bia, $this->ti, manager: true);
        $this->joinSector($this->caio, $this->ti);
        $this->profile = $this->profileOf($this->bia, $this->acme, $this->ti);
    }

    // Evidência — o membro que vê o alerta não grava nada por nenhuma porta de configuração com `?alerta=`: a entrevista
    // (passo e concluir), o domínio (comprovar e reler) e o "Novo alerta" copiando dele; nada muda e nada é criado.
    public function test_evidencia_membro_nao_configura_por_nenhuma_porta(): void
    {
        $q = ['alerta' => $this->profile->id];
        $before = AlertProfile::query()->count();

        $this->actingAs($this->caio)->put(route('alerts.interview.update', ['passo' => 1] + $q), ['company_name' => 'Invasão'])->assertNotFound();
        $this->actingAs($this->caio)->post(route('alerts.interview.finish', $q))->assertNotFound();
        $this->actingAs($this->caio)->post(route('alerts.domain.verify', $q))->assertNotFound();
        $this->actingAs($this->caio)->post(route('alerts.domain.rerun', $q))->assertNotFound();
        // Copiar exige configurar a origem (decisão da E2c2): 404 antes de qualquer regra.
        $this->actingAs($this->caio)->post(route('alerts.store', ['copiar' => $this->profile->id]))->assertNotFound();

        $this->assertSame($before, AlertProfile::query()->count());
        $this->assertNotSame('Invasão', $this->profile->fresh()->company_name);
        $this->assertSame(0, DB::table('alert_profile_answers')->where('profile_id', $this->profile->id)->count());
    }

    // Evidência — o segurado (AlertsFlush) não sai depois que quem criou saiu do setor; o voto e o reenvio também não.
    // E5b1: o segurado do TI sai pelo canal do TI mesmo depois que quem o criou saiu do setor; ela, fora do setor, não
    // vota nem reenvia.
    public function test_evidencia_segurado_sai_no_canal_do_setor_mesmo_depois_que_o_criador_sai(): void
    {
        $ti = $this->companyChannel($this->ana, $this->acme, $this->ti);
        $case = NewsCase::query()->create(['title' => 'Caso', 'fingerprint' => Str::random(32), 'is_published' => true]);
        $match = AlertMatch::query()->create([
            'profile_id' => $this->profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'criticity' => 'high', 'score' => 60,
            'delivery_status' => 'held', 'release_at' => now()->subMinute(), 'matched_at' => now(),
        ]);

        $this->ti->users()->detach($this->bia->id);
        $this->artisan('secmon:alerts-flush')->assertSuccessful();

        $this->assertSame('pending', $match->fresh()->delivery_status);
        $this->assertSame([$ti->id], DB::table('notification_deliveries')->pluck('channel_id')->all());
        $this->actingAs($this->bia)->post(route('alerts.feedback', $match), ['feedback' => 'irrelevant'])->assertNotFound();
        $this->actingAs($this->bia)->post(route('alerts.resend', $match))->assertNotFound();
        $this->assertNull($match->fresh()->feedback);
    }
}
