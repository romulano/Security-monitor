<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\NewsCase;
use App\Models\NotificationDelivery;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\DigestBuilder;
use App\Services\Company\Destinations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E5b1 (DESENHO-5.4 §8.3): o alerta de um setor nunca vai a outro. Só evidências (nada a corrigir). */
class RedTeamE5b1Test extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $bia;

    private User $caio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bia = tap($this->companyAccount($this->acme, CompanyRole::SectorManager), fn (User $u) => $u->forceFill(['email_verified_at' => now()])->save());
        $this->joinSector($this->bia, $this->ti, manager: true);
        $this->caio = tap($this->companyAccount($this->acme, CompanyRole::SectorManager), fn (User $u) => $u->forceFill(['email_verified_at' => now()])->save());
        $this->joinSector($this->caio, $this->rh, manager: true);
    }

    private function match($profile): AlertMatch
    {
        $case = NewsCase::query()->create(['title' => 'Caso '.Str::random(6), 'fingerprint' => Str::random(32), 'is_published' => true]);

        return AlertMatch::query()->create(['profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'criticity' => 'high', 'score' => 60, 'matched_at' => now(),
            'delivery_status' => 'sent']);
    }

    // O reenvio sai pelos canais do alerta; o responsável do RH (que vê o RH, não o TI) nem acha o alerta do TI.
    public function test_evidencia_resend_goes_to_the_item_channels_and_only_who_manages_it(): void
    {
        $ti = $this->companyChannel($this->bia, $this->acme, $this->ti);
        $rh = $this->companyChannel($this->caio, $this->acme, $this->rh);
        $general = $this->companyChannel($this->ana, $this->acme);
        $this->rh->forceFill(['copy_to_general' => true])->save();
        $match = $this->match($this->profileOf($this->bia, $this->acme, $this->ti));

        $this->actingAs($this->caio)->post(route('alerts.resend', $match))->assertNotFound();
        $this->assertSame(0, NotificationDelivery::query()->count());

        $this->actingAs($this->ana)->post(route('alerts.resend', $match));
        $this->assertSame([$ti->id], NotificationDelivery::query()->pluck('channel_id')->unique()->values()->all(), 'nem o RH nem o geral (a cópia é do RH)');
        $this->assertNotContains($rh->id, NotificationDelivery::query()->pluck('channel_id')->all());
        $this->assertNotContains($general->id, NotificationDelivery::query()->pluck('channel_id')->all());
    }

    // O resumo do canal do RH nunca leva fonte do TI, nem com a cópia do TI ligada (a cópia vai aos gerais, não a outro setor).
    public function test_evidencia_the_digest_of_a_sector_never_takes_another_sector(): void
    {
        $rh = $this->companyChannel($this->caio, $this->acme, $this->rh, ['digest_scope' => 'all']);
        $this->ti->forceFill(['copy_to_general' => true])->save();
        $this->profileOf($this->bia, $this->acme, $this->ti);
        $this->watchOf($this->bia, $this->acme, $this->ti);
        $this->monitorOf($this->bia, $this->acme, $this->ti);

        $sources = app(Destinations::class);
        foreach ([AlertProfile::class, LookalikeWatch::class, GithubMonitor::class] as $model) {
            $this->assertSame(0, $sources->sourcesFor($rh, $model::query())->count(), $model);
        }

        $digest = app(DigestBuilder::class)->build($rh->refresh(), Carbon::now('America/Sao_Paulo')->startOfDay());
        $this->assertSame([[], [], []], [$digest['mine'], $digest['radar'], $digest['github']]);
    }

    // A chave do destino nunca junta dois setores (nem setor e geral) e a conta pessoal fica na dela.
    public function test_evidencia_destination_keys_never_merge_scopes(): void
    {
        $d = app(Destinations::class);
        $keys = [
            $d->key($this->profileOf($this->bia, $this->acme, $this->ti)),
            $d->key($this->profileOf($this->caio, $this->acme, $this->rh)),
            $d->key($this->profileOf($this->ana, $this->acme)),
            $d->key($this->profileOf(User::factory()->create())),
        ];

        $this->assertCount(4, array_unique($keys));
    }
}
