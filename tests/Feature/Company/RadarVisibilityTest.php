<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\LookalikeAlert;
use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\ProfileIndex;
use App\Services\Company\Companies;
use App\Services\Lookalike\LookalikeAlertRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Feature\Alerts\FakeProfileIndex;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E2d1: o Radar pela visibilidade (R4, R10, R14; DESENHO-5.2 §2.1 e §4.4 opção D). Ver é de quem está no setor (e da
 * admin); configurar, da admin e de quem responde pelo setor; criar e trocar o setor, com o setor conferido no servidor.
 * Cada rota do Radar com um item na URL é aberta como conta de outro setor e de outra empresa, esperando 404.
 */
class RadarVisibilityTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    /** Bruno responde pelo TI; Caio é membro do TI; Dani é membro do RH. */
    private User $bruno;

    private User $caio;

    private User $dani;

    private User $paulo;

    private LookalikeWatch $tiWatch;

    private LookalikeWatch $rhWatch;

    private LookalikeDomain $tiLookalike;

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
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
        $this->dani = $this->companyAccount($this->acme);
        $this->joinSector($this->dani, $this->rh);
        [, $this->paulo] = $this->activeCompany('Beta', 'paulo@beta.com');

        $this->tiWatch = $this->ready($this->watchOf($this->bruno, $this->acme, $this->ti));
        $this->rhWatch = $this->ready($this->watchOf($this->ana, $this->acme, $this->rh));
        $this->tiLookalike = $this->tiWatch->lookalikes()->create(['host' => 'acme-login.com', 'first_seen_at' => now(), 'risk' => 'high']);
    }

    private function ready(LookalikeWatch $watch): LookalikeWatch
    {
        $watch->forceFill(['status' => 'active', 'completed_at' => now(), 'step_reached' => 3, 'scan_status' => 'done'])->save();

        return $watch->fresh();
    }

    public function test_the_page_lists_only_the_radars_the_account_sees(): void
    {
        $everyone = $this->ready($this->watchOf($this->ana, $this->acme));
        [$ti, $rh, $all] = [$this->tiWatch->domain->domain, $this->rhWatch->domain->domain, $everyone->domain->domain];

        $this->actingAs($this->caio)->get(route('alerts.lookalikes', ['radar' => $this->tiWatch->id]))->assertOk()
            ->assertSee($ti)->assertSee($all)->assertDontSee($rh);
        $this->actingAs($this->dani)->get(route('alerts.lookalikes', ['radar' => $this->rhWatch->id]))->assertOk()
            ->assertSee($rh)->assertSee($all)->assertDontSee($ti);
        $this->actingAs($this->ana)->get(route('alerts.lookalikes', ['radar' => $everyone->id]))->assertOk()
            ->assertSee($ti)->assertSee($rh)->assertSee($all);

        // `?radar=` de outro setor ou de outra empresa: 404, como se não existisse.
        $this->actingAs($this->caio)->get(route('alerts.lookalikes', ['radar' => $this->rhWatch->id]))->assertNotFound();
        $this->actingAs($this->paulo)->get(route('alerts.lookalikes', ['radar' => $this->tiWatch->id]))->assertNotFound();
        $this->actingAs($this->paulo)->get(route('alerts.lookalikes'))->assertOk()->assertDontSee($ti)->assertDontSee($all);

        // O Radar de empresa sempre leva o `?radar=` (o "primeiro" de cada conta é outro).
        $this->assertSame(['radar' => $everyone->id], $everyone->urlParams());
    }

    public function test_the_member_sees_and_votes_but_does_not_configure(): void
    {
        $radar = ['radar' => $this->tiWatch->id];

        $this->actingAs($this->caio)->get(route('alerts.lookalikes', $radar))->assertOk()
            ->assertDontSee(route('alerts.lookalikes.pause', $radar))->assertDontSee('Editar entrevista')->assertDontSee(route('alerts.lookalikes.sector', $radar));
        $this->actingAs($this->caio)->get(route('alerts.lookalikes.show', $this->tiLookalike))->assertOk();
        $this->actingAs($this->caio)->get(route('alerts.lookalikes.kit', $this->tiLookalike))->assertOk();
        $this->actingAs($this->caio)->post(route('alerts.lookalikes.verdict', $this->tiLookalike), ['acao' => 'golpe'])->assertRedirect();
        $this->assertSame('threat', $this->tiLookalike->fresh()->verdict);

        foreach ([[$this->caio, $this->tiWatch], [$this->bruno, $this->rhWatch]] as [$who, $watch]) {
            $radar = ['radar' => $watch->id];
            $this->actingAs($who)->post(route('alerts.lookalikes.pause', $radar))->assertNotFound();
            $this->actingAs($who)->post(route('alerts.lookalikes.scan', $radar))->assertNotFound();
            $this->actingAs($who)->delete(route('alerts.lookalikes.destroy', $radar))->assertNotFound();
            $this->actingAs($who)->get(route('alerts.lookalikes.interview', ['passo' => 2] + $radar))->assertNotFound();
            $this->actingAs($who)->put(route('alerts.lookalikes.interview.update', ['passo' => 2] + $radar), ['empresa' => 'X'])->assertNotFound();
            $this->actingAs($who)->post(route('alerts.lookalikes.interview.verify', $radar))->assertNotFound();
            $this->actingAs($who)->post(route('alerts.lookalikes.interview.activate', $radar))->assertNotFound();
        }

        // Quem responde pelo TI configura o Radar do TI; o de empresa, só com o `?radar=` explícito.
        $this->actingAs($this->bruno)->post(route('alerts.lookalikes.pause'))->assertNotFound();
        $this->actingAs($this->bruno)->post(route('alerts.lookalikes.pause', ['radar' => $this->tiWatch->id]))->assertRedirect();
        $this->assertSame('paused', $this->tiWatch->fresh()->status);
        // O sem setor é da empresa toda: o responsável vê, só a admin configura (P1).
        $everyone = $this->ready($this->watchOf($this->ana, $this->acme));
        $this->actingAs($this->bruno)->post(route('alerts.lookalikes.pause', ['radar' => $everyone->id]))->assertNotFound();
        $this->actingAs($this->ana)->post(route('alerts.lookalikes.pause', ['radar' => $everyone->id]))->assertRedirect();
        $this->assertSame([$this->tiWatch->id, $this->rhWatch->id, $everyone->id], LookalikeWatch::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_integration_offers_to_add_or_continue_a_radar_only_to_who_configures(): void
    {
        $draft = $this->watchOf($this->bruno, $this->acme, $this->ti);
        $draft->forceFill(['status' => 'draft', 'step_reached' => 2])->save();
        $continue = route('alerts.lookalikes.interview', ['passo' => $draft->step_reached, 'radar' => $draft->id]);

        // O membro vê os Radares do setor, mas não entra na entrevista nem cria.
        $this->actingAs($this->caio)->get(route('alerts.integrations'))->assertOk()
            ->assertDontSee($continue)->assertDontSee(route('alerts.lookalikes.interview', ['passo' => 1]));
        $this->actingAs($this->bruno)->get(route('alerts.integrations'))->assertOk()->assertSee($continue);
        // Na empresa não há limite de quem cria (E5d, P7): a admin com cinco Radares criados ainda adiciona (quem
        // limita é o domínio: um Radar por domínio comprovado da empresa).
        foreach (range(1, 5) as $i) {
            $this->ready($this->watchOf($this->ana, $this->acme));
        }
        $draft->delete();
        $this->actingAs($this->ana)->get(route('alerts.integrations'))->assertOk()->assertSee(route('alerts.lookalikes.interview', ['passo' => 1]));
        $this->actingAs($this->bruno)->get(route('alerts.integrations'))->assertOk()->assertSee(route('alerts.lookalikes.interview', ['passo' => 1]));
    }

    // Opção D do DESENHO-5.2 §4.4: cada rota com item do Radar na URL, como conta de outro setor e de outra empresa.
    public function test_every_radar_route_with_an_item_is_404_for_another_sector_and_another_company(): void
    {
        $capture = LookalikeCapture::query()->create(['lookalike_id' => $this->tiLookalike->id, 'requested_at' => now()]);
        $routes = [
            ['get', route('alerts.lookalikes.show', $this->tiLookalike), []],
            ['get', route('alerts.lookalikes.kit', $this->tiLookalike), []],
            ['post', route('alerts.lookalikes.verdict', $this->tiLookalike), ['acao' => 'seguro']],
            ['post', route('alerts.lookalikes.report', [$this->tiLookalike, 'registrar']), []],
            ['get', route('alerts.lookalikes.shot', [$capture, 'desktop', 'first']), []],
            ['get', route('alerts.lookalikes.reference', [$this->tiWatch, 1, 'desktop']), []],
            ['get', route('alerts.lookalikes', ['radar' => $this->tiWatch->id]), []],
            ['put', route('alerts.lookalikes.sector', ['radar' => $this->tiWatch->id]), ['setor' => $this->rh->id]],
        ];

        foreach ([$this->dani, $this->paulo, User::factory()->create()] as $who) {
            foreach ($routes as [$method, $url, $data]) {
                $this->actingAs($who)->{$method}($url, $data)->assertNotFound();
            }
        }

        $this->assertNull($this->tiLookalike->fresh()->verdict);
        $this->assertSame($this->ti->id, $this->tiWatch->fresh()->sector_id);
    }

    public function test_a_new_radar_gets_the_sector_the_account_may_use(): void
    {
        $create = fn (User $who, string $domain, $sector) => $this->actingAs($who)
            ->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => $domain, 'setor' => $sector]);

        $this->actingAs($this->ana)->get(route('alerts.lookalikes.interview', ['passo' => 1]))->assertOk()
            ->assertSee('Sem setor (toda a empresa vê)')->assertSee('<option value="'.$this->rh->id.'"', false);
        $this->actingAs($this->ana)->get(route('alerts.lookalikes.interview', ['passo' => 1]))
            ->assertSee('aria-describedby="setor-hint"', false)
            ->assertSee('Quem está no setor vê o Radar e os domínios achados e recebe os avisos; quem administra a empresa vê tudo. Sem setor, toda a empresa vê. Dá para trocar depois.');
        // Quem responde por um setor só: o setor vem escrito, sem escolha (Designer, rodada 8).
        $this->actingAs($this->bruno)->get(route('alerts.lookalikes.interview', ['passo' => 1]))->assertOk()
            ->assertDontSee('Sem setor (toda a empresa vê)')->assertDontSee('<option value="'.$this->rh->id.'"', false)
            ->assertSee('Setor da empresa que vê este Radar: TI')->assertSee('<input type="hidden" name="setor" value="'.$this->ti->id.'">', false)
            ->assertSee('É o único setor pelo qual você responde.');
        // Com dois setores e sem "sem setor", nada vem escolhido em silêncio.
        $this->joinSector($this->bruno, $this->rh, manager: true);
        $this->actingAs($this->bruno)->get(route('alerts.lookalikes.interview', ['passo' => 1]))
            ->assertSee('<option value="" disabled selected>Escolha o setor…</option>', false)->assertSee('required', false);
        $this->rh->users()->detach($this->bruno->id);
        $this->actingAs($this->caio)->get(route('alerts.lookalikes.interview', ['passo' => 1]))->assertNotFound();
        $create($this->caio, 'caio.com.br', $this->ti->id)->assertNotFound();

        $create($this->ana, 'geral.com.br', '')->assertRedirect();
        $create($this->ana, 'rh-acme.com.br', $this->rh->id)->assertRedirect();
        $create($this->bruno, 'fora.com.br', $this->rh->id)->assertSessionHas('error', 'Escolha um setor que você pode usar.');
        $create($this->bruno, 'semsetor.com.br', '')->assertSessionHas('error', 'Escolha um setor que você pode usar.');
        $create($this->bruno, 'ti-acme.com.br', $this->ti->id)->assertRedirect();
        $create($this->ana, 'beta.com.br', Sector::query()->forceCreate(['company_id' => $this->paulo->companyMembership->company_id, 'name' => 'Vendas'])->id)
            ->assertSessionHas('error');

        $made = LookalikeWatch::query()->with('domain')->whereNull('completed_at')->get()->mapWithKeys(fn ($w) => [$w->domain->domain => [$w->user_id, $w->company_id, $w->sector_id]]);
        $this->assertEquals([
            'geral.com.br' => [$this->ana->id, $this->acme->id, null],
            'rh-acme.com.br' => [$this->ana->id, $this->acme->id, $this->rh->id],
            'ti-acme.com.br' => [$this->bruno->id, $this->acme->id, $this->ti->id],
        ], $made->sortKeys()->all());

        // Quem responde pelo TI continua a entrevista do Radar do TI que a admin criou; a conta pessoal, como sempre.
        $adminTi = $this->ready($this->watchOf($this->ana, $this->acme, $this->ti));
        $this->actingAs($this->bruno)->get(route('alerts.lookalikes.interview', ['passo' => 2, 'radar' => $adminTi->id]))->assertOk();
        $personal = User::factory()->create();
        $create($personal, 'pessoal.com.br', $this->ti->id)->assertSessionHas('error');
        $create($personal, 'pessoal.com.br', '')->assertRedirect();
        $this->assertSame([null, null], [LookalikeWatch::query()->where('user_id', $personal->id)->sole()->company_id, LookalikeWatch::query()->where('user_id', $personal->id)->sole()->sector_id]);
    }

    // Red Team da E2f: o setor apagado no instante em que um Radar nasce nele vira a mensagem, e não um erro de FK.
    public function test_a_sector_deleted_while_a_radar_is_created_in_it_gives_a_message(): void
    {
        $gone = false;
        DB::listen(function ($query) use (&$gone) {
            // Logo depois de o Visibility conferir o setor (fora da trava), outra requisição apaga o setor.
            if (! $gone && str_contains($query->sql, 'from "sectors"') && ! str_contains($query->sql, 'for update')) {
                $gone = true;
                DB::table('sectors')->where('id', $this->rh->id)->delete();
            }
        });

        $this->actingAs($this->ana)->put(route('alerts.lookalikes.interview.update', ['passo' => 1]), ['origem' => 'outro', 'outro' => 'corrida.com.br', 'setor' => $this->rh->id])
            ->assertSessionHas('error', 'O setor foi apagado. Escolha outro.');
        $this->assertFalse(LookalikeWatch::query()->whereHas('domain', fn ($q) => $q->where('domain', 'corrida.com.br'))->exists());
    }

    public function test_changing_the_sector_follows_the_rules_and_goes_to_the_activity(): void
    {
        $move = fn (User $who, LookalikeWatch $watch, $sector) => $this->actingAs($who)
            ->put(route('alerts.lookalikes.sector', ['radar' => $watch->id]), ['setor' => $sector]);
        $domain = $this->tiWatch->domain->domain;

        $this->actingAs($this->ana)->get(route('alerts.lookalikes', ['radar' => $this->tiWatch->id]))
            ->assertSee('Setor: <span class="text-ink-200">TI</span>', false)->assertSee(route('alerts.lookalikes.sector', ['radar' => $this->tiWatch->id]))
            // A pergunta vem da opção escolhida, e a atual não é um clique vazio (Designer, rodada 8).
            ->assertSee('TI (atual)')
            // O Radar é do Bruno, que não está no RH: a pergunta avisa que ele fica mudo (Red Team da E2d1).
            ->assertSee('data-confirm="'.e("Trocar o setor do Radar de {$domain} para RH? Quem está só em TI deixa de ver este Radar e os domínios achados. Atenção: quem criou este Radar não está em RH. Até os canais serem separados por setor, os avisos deste Radar param.").'"', false)
            ->assertSee('data-confirm="'.e("Deixar o Radar de {$domain} sem setor? Toda a empresa passa a ver este Radar e os domínios achados.").'"', false);
        // Radar criado pela admin: ela vê todos os setores, então não há aviso.
        $this->actingAs($this->ana)->get(route('alerts.lookalikes', ['radar' => $this->rhWatch->id]))
            ->assertSee('RH (atual)')->assertDontSee('os avisos deste Radar param')->assertDontSee('data-confirm-tone="danger"', false);

        // O responsável troca só entre os setores que gerencia: nunca para outro nem para "sem setor".
        $move($this->bruno, $this->tiWatch, $this->rh->id)->assertSessionHas('error', 'Escolha um setor que você pode usar.');
        $move($this->bruno, $this->tiWatch, 'sem')->assertSessionHas('error');
        $move($this->caio, $this->tiWatch, $this->rh->id)->assertNotFound();
        $this->assertSame($this->ti->id, $this->tiWatch->fresh()->sector_id);

        // A admin troca: quem só está no TI deixa de ver; a Atividade registra.
        $move($this->ana, $this->tiWatch, $this->rh->id)->assertSessionHas('status', 'Setor do Radar trocado.');
        $this->assertSame($this->rh->id, $this->tiWatch->fresh()->sector_id);
        $this->actingAs($this->caio)->get(route('alerts.lookalikes.show', $this->tiLookalike))->assertNotFound();
        $this->actingAs($this->dani)->get(route('alerts.lookalikes.show', $this->tiLookalike))->assertOk();
        $move($this->ana, $this->tiWatch, 'sem')->assertSessionHas('status');
        $this->assertNull($this->tiWatch->fresh()->sector_id);
        // Vindo de "sem setor", a pergunta diz quem passa a ver (Designer, rodada 10b).
        $this->actingAs($this->ana)->get(route('alerts.lookalikes', ['radar' => $this->tiWatch->id]))
            ->assertSee(e("Trocar o setor do Radar de {$domain} para RH? A partir daí, só quem está em RH (e quem administra a empresa) vê este Radar e os domínios achados."), false);

        $this->actingAs($this->ana)->get(route('company.activity'))->assertOk()
            ->assertSee("Radar {$domain}: setor TI → RH")
            ->assertSee("Radar {$domain}: setor RH → sem setor");
        $this->assertSame(2, DB::table('company_audit')->where('action', 'item.sector_changed')->count());

        // Radar pessoal não tem setor para trocar.
        $personal = User::factory()->create();
        $own = $this->ready($this->watchOf($personal));
        $move($personal, $own, 'sem')->assertNotFound();
    }

    // E5b1: o aviso do Radar do TI vai ao canal do TI, com ou sem quem o criou no setor; a DM dele não recebe nada.
    public function test_the_alert_goes_to_the_sector_channel_whoever_created_the_radar(): void
    {
        $personal = NotificationChannel::query()->create(['user_id' => $this->bruno->id, 'type' => 'discord', 'method' => 'dm', 'status' => 'active', 'realtime' => true, 'digest' => false, 'timezone' => 'America/Sao_Paulo']);
        $ti = $this->companyChannel($this->bruno, $this->acme, $this->ti);
        $alert = fn () => LookalikeAlert::query()->create(['lookalike_id' => $this->tiLookalike->id, 'kind' => 'new', 'risk' => 'critical']);

        $this->ti->users()->detach($this->bruno->id);
        app(LookalikeAlertRouter::class)->route($first = $alert());
        $this->assertSame('queued', $first->fresh()->status);
        $this->assertSame([$ti->id], DB::table('notification_deliveries')->pluck('channel_id')->all());
        $this->assertFalse(DB::table('notification_deliveries')->where('channel_id', $personal->id)->exists());

        // A empresa suspensa (D7): nada sai.
        app(Companies::class)->suspend($this->acme);
        $this->refreshVisibility();
        app(LookalikeAlertRouter::class)->route($second = $alert());
        $this->assertSame(['skipped', 'company_paused'], [$second->fresh()->status, $second->fresh()->note]);
        $this->assertSame(1, DB::table('notification_deliveries')->count());
    }
}
