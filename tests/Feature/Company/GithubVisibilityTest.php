<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\GithubFinding;
use App\Models\GithubMonitor;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Github\GithubAlertRouter;
use App\Services\Github\GithubInterview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E2d2: o GitHub pela visibilidade (R4, R14, P6; DESENHO-5.2 §2.1 e §4.4 opção D). Vários monitoramentos por empresa
 * (até `limit_github_monitors`), com o `?monitor=`; ver é de quem está no setor, configurar e reenviar de quem responde
 * por ele (e da admin). Cada rota do GitHub com item é aberta como outro setor e outra empresa, esperando 404.
 */
class GithubVisibilityTest extends TestCase
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

    private GithubMonitor $tiMonitor;

    private GithubMonitor $rhMonitor;

    private GithubFinding $tiFinding;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
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

        $this->tiMonitor = $this->ready($this->monitorOf($this->bruno, $this->acme, $this->ti), 'acme-ti');
        $this->rhMonitor = $this->ready($this->monitorOf($this->ana, $this->acme, $this->rh), 'acme-rh');
        $this->tiFinding = GithubFinding::query()->create([
            'monitor_id' => $this->tiMonitor->id, 'fingerprint' => Str::random(40), 'kind' => 'key_leak', 'repo' => 'fulano/teste',
            'html_url' => 'https://github.com/fulano/teste', 'auto_level' => 'high', 'status' => 'sent', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    private function ready(GithubMonitor $monitor, string $org): GithubMonitor
    {
        $monitor->forceFill(['status' => 'active', 'completed_at' => now(), 'step_reached' => 6, 'orgs' => [$org], 'checks' => array_keys(GithubMonitor::CHECKS), 'min_level' => 'high'])->save();

        return $monitor->fresh();
    }

    public function test_the_page_lists_only_the_monitors_the_account_sees(): void
    {
        $everyone = $this->ready($this->monitorOf($this->ana, $this->acme), 'acme-geral');

        $this->actingAs($this->caio)->get(route('alerts.github', ['monitor' => $this->tiMonitor->id]))->assertOk()
            ->assertSee('github.com/acme-ti')->assertSee('github.com/acme-geral')->assertDontSee('github.com/acme-rh');
        $this->actingAs($this->ana)->get(route('alerts.github', ['monitor' => $everyone->id]))->assertOk()
            ->assertSee('github.com/acme-ti')->assertSee('github.com/acme-rh')->assertSee(route('alerts.github', ['monitor' => $this->rhMonitor->id]));

        $this->actingAs($this->caio)->get(route('alerts.github', ['monitor' => $this->rhMonitor->id]))->assertNotFound();
        $this->actingAs($this->paulo)->get(route('alerts.github', ['monitor' => $this->tiMonitor->id]))->assertNotFound();
        $this->actingAs($this->paulo)->get(route('alerts.github'))->assertOk()->assertDontSee('acme-ti');

        // O de empresa sempre leva o `?monitor=`; o pessoal continua com as URLs de sempre.
        $this->assertSame(['monitor' => $everyone->id], $everyone->urlParams());
        $this->assertSame([], $this->ready($this->monitorOf(User::factory()->create()), 'pessoal')->urlParams());
    }

    public function test_the_member_sees_and_votes_but_does_not_configure_nor_resend(): void
    {
        $this->actingAs($this->caio)->get(route('alerts.github', ['monitor' => $this->tiMonitor->id]))->assertOk()
            ->assertDontSee('Editar entrevista')->assertDontSee(route('alerts.github.pause', ['monitor' => $this->tiMonitor->id]));
        $this->actingAs($this->caio)->get(route('alerts.github.show', $this->tiFinding))->assertOk()->assertDontSee(route('alerts.github.resend', $this->tiFinding));
        $this->actingAs($this->caio)->post(route('alerts.github.feedback', $this->tiFinding), ['feedback' => 'relevant'])->assertRedirect();
        $this->assertSame('relevant', $this->tiFinding->fresh()->feedback);
        $this->actingAs($this->caio)->post(route('alerts.github.resend', $this->tiFinding))->assertNotFound();

        foreach ([[$this->caio, $this->tiMonitor], [$this->bruno, $this->rhMonitor]] as [$who, $monitor]) {
            $at = ['monitor' => $monitor->id];
            $this->actingAs($who)->post(route('alerts.github.pause', $at))->assertNotFound();
            $this->actingAs($who)->post(route('alerts.github.scan', $at))->assertNotFound();
            $this->actingAs($who)->delete(route('alerts.github.destroy', $at))->assertNotFound();
            $this->actingAs($who)->post(route('alerts.github.interview.finish', $at))->assertNotFound();
            $this->actingAs($who)->get(route('alerts.github.interview', ['passo' => 2] + $at))->assertNotFound();
            $this->actingAs($who)->put(route('alerts.github.interview.update', ['passo' => 2] + $at), ['answers' => []])->assertNotFound();
        }

        // Quem responde pelo TI configura o do TI, só com o `?monitor=` explícito; o sem setor, só a admin (P1).
        $this->actingAs($this->bruno)->post(route('alerts.github.pause'))->assertNotFound();
        $this->actingAs($this->bruno)->post(route('alerts.github.pause', ['monitor' => $this->tiMonitor->id]))->assertRedirect();
        $this->assertSame('paused', $this->tiMonitor->fresh()->status);
        $everyone = $this->ready($this->monitorOf($this->ana, $this->acme), 'acme-geral');
        $this->actingAs($this->bruno)->post(route('alerts.github.pause', ['monitor' => $everyone->id]))->assertNotFound();
        $this->actingAs($this->bruno)->get(route('alerts.github.interview', ['passo' => 2, 'monitor' => $this->tiMonitor->id]))->assertOk();
        $this->assertSame(3, GithubMonitor::query()->count());
    }

    // Opção D do DESENHO-5.2 §4.4: cada rota com item do GitHub na URL, como conta de outro setor e de outra empresa.
    public function test_every_github_route_with_an_item_is_404_for_another_sector_and_another_company(): void
    {
        $routes = [
            ['get', route('alerts.github.show', $this->tiFinding), []],
            ['post', route('alerts.github.feedback', $this->tiFinding), ['feedback' => 'irrelevant']],
            ['post', route('alerts.github.resend', $this->tiFinding), []],
            ['get', route('alerts.github', ['monitor' => $this->tiMonitor->id]), []],
            ['put', route('alerts.github.sector', ['monitor' => $this->tiMonitor->id]), ['setor' => $this->rh->id]],
            ['get', route('alerts.github.interview', ['passo' => 1, 'monitor' => $this->tiMonitor->id]), []],
        ];

        foreach ([$this->dani, $this->paulo, User::factory()->create()] as $who) {
            foreach ($routes as [$method, $url, $data]) {
                $this->actingAs($who)->{$method}($url, $data)->assertNotFound();
            }
        }

        $this->assertNull($this->tiFinding->fresh()->feedback);
        $this->assertSame($this->ti->id, $this->tiMonitor->fresh()->sector_id);
    }

    public function test_a_new_monitor_gets_the_sector_and_respects_the_company_limit(): void
    {
        $create = fn (User $who, string $org, $sector) => $this->actingAs($who)
            ->put(route('alerts.github.interview.update', ['passo' => 1]), ['answers' => ['orgs' => [$org]], 'setor' => $sector]);

        $this->actingAs($this->ana)->get(route('alerts.github.interview', ['passo' => 1]))->assertOk()
            ->assertSee('Sem setor (toda a empresa vê)')->assertSee('Quem está no setor vê o monitoramento e os achados e recebe os avisos;');
        $this->actingAs($this->bruno)->get(route('alerts.github.interview', ['passo' => 1]))->assertOk()
            ->assertSee('Setor da empresa que vê este monitoramento: TI')->assertDontSee('Sem setor (toda a empresa vê)');
        // Sem `?monitor=`, os outros passos de empresa voltam ao 1 (o novo nasce lá); o membro nem chega.
        $this->actingAs($this->bruno)->get(route('alerts.github.interview', ['passo' => 3]))->assertRedirect(route('alerts.github.interview', ['passo' => 1]));
        $this->actingAs($this->caio)->get(route('alerts.github.interview', ['passo' => 1]))->assertNotFound();
        $create($this->caio, 'caio', $this->ti->id)->assertNotFound();

        $create($this->bruno, 'fora', $this->rh->id)->assertSessionHas('error', 'Escolha um setor que você pode usar.');
        $response = $create($this->bruno, 'acme-novo', $this->ti->id);
        $new = GithubMonitor::query()->latest('id')->first();
        $response->assertRedirect(route('alerts.github.interview', ['passo' => 2, 'monitor' => $new->id]));
        $this->assertSame([$this->bruno->id, $this->acme->id, $this->ti->id, ['acme-novo']], [$new->user_id, $new->company_id, $new->sector_id, $new->orgs]);
        $create($this->ana, 'acme-geral', '')->assertRedirect();
        $this->assertNull(GithubMonitor::query()->latest('id')->first()->sector_id);

        // Resposta inválida não deixa monitoramento vazio para trás.
        $before = GithubMonitor::query()->count();
        $create($this->ana, 'nome com espaço', '')->assertSessionHasErrors('answers.orgs');
        $this->assertSame($before, GithubMonitor::query()->count());

        // Duplo clique no passo 1 (Red Team da E2d2): o segundo envio cai no rascunho do primeiro; o botão tem data-once.
        $twice = GithubMonitor::query()->count();
        $create($this->bruno, 'acme-duplo', $this->ti->id)->assertRedirect();
        $first = GithubMonitor::query()->latest('id')->first();
        $create($this->bruno, 'acme-duplo', $this->ti->id)->assertRedirect(route('alerts.github.interview', ['passo' => 2, 'monitor' => $first->id]));
        $this->assertSame($twice + 1, GithubMonitor::query()->count());
        $this->actingAs($this->bruno)->get(route('alerts.github.interview', ['passo' => 1]))->assertSee('data-once', false);
        $this->travel(GithubInterview::DOUBLE_SUBMIT_SECONDS + 1)->seconds();

        // O limite da empresa (P6): o 6º é recusado, e o "Novo" some.
        app(Companies::class)->setLimits($this->acme, ['github_monitors' => GithubMonitor::query()->where('company_id', $this->acme->id)->count()]);
        $create($this->ana, 'mais-um', '')->assertSessionHas('error', fn ($e) => str_contains($e, 'monitoramentos do GitHub, o máximo'));
        // O serviço confere de novo com a empresa travada (dois pedidos juntos não passam do limite).
        try {
            app(GithubInterview::class)->startWith($this->ana, null, ['orgs' => ['mais-um']]);
            $this->fail('Passou do limite de monitoramentos da empresa.');
        } catch (ActionRefused $e) {
            $this->assertStringContainsString('monitoramentos do GitHub, o máximo', $e->getMessage());
        }
        $this->actingAs($this->ana)->get(route('alerts.github', ['monitor' => $new->id]))->assertDontSee('Adicionar monitoramento');

        // A conta pessoal segue com um só, criado sozinho na entrevista, sem setor.
        $personal = User::factory()->create();
        $this->actingAs($personal)->get(route('alerts.github.interview', ['passo' => 1]))->assertOk();
        $this->assertSame([null, null], [GithubMonitor::query()->where('user_id', $personal->id)->sole()->company_id, GithubMonitor::query()->where('user_id', $personal->id)->sole()->sector_id]);
    }

    public function test_changing_the_sector_follows_the_rules_warns_when_mute_and_goes_to_the_activity(): void
    {
        $move = fn (User $who, $sector) => $this->actingAs($who)->put(route('alerts.github.sector', ['monitor' => $this->tiMonitor->id]), ['setor' => $sector]);

        // O monitoramento é do Bruno, que não está no RH: a pergunta avisa que ele fica mudo; o "sem setor", não.
        $this->actingAs($this->ana)->get(route('alerts.github', ['monitor' => $this->tiMonitor->id]))
            ->assertSee('TI (atual)')
            // Designer, rodada 9: o aviso de mudo vem no tom de perigo, só na opção em que o monitoramento para.
            ->assertSee('data-confirm="'.e('Trocar o setor do monitoramento github.com/acme-ti para RH? Quem está só em TI deixa de ver este monitoramento e os achados. Atenção: quem criou este monitoramento não está em RH. Até os canais serem separados por setor, os avisos deste monitoramento param.').'" data-confirm-ok="Trocar para RH"', false)
            ->assertSee('data-confirm-tone="danger"', false)
            ->assertSee('data-confirm="'.e('Deixar o monitoramento github.com/acme-ti sem setor? Toda a empresa passa a ver este monitoramento e os achados.').'"', false);

        $move($this->bruno, $this->rh->id)->assertSessionHas('error', 'Escolha um setor que você pode usar.');
        $move($this->bruno, 'sem')->assertSessionHas('error');
        $move($this->caio, $this->rh->id)->assertNotFound();
        $move($this->ana, $this->rh->id)->assertSessionHas('status', 'Setor do monitoramento trocado.');
        $this->actingAs($this->caio)->get(route('alerts.github.show', $this->tiFinding))->assertNotFound();
        $this->actingAs($this->dani)->get(route('alerts.github.show', $this->tiFinding))->assertOk();

        $this->actingAs($this->ana)->get(route('company.activity'))->assertSee('GitHub github.com/acme-ti: setor TI → RH');
    }

    public function test_the_integration_shows_every_visible_monitor_and_offers_only_what_the_account_may_do(): void
    {
        $this->actingAs($this->caio)->get(route('alerts.integrations'))->assertOk()
            ->assertSee('Abrir o GitHub')->assertDontSee('Adicionar monitoramento')->assertDontSee('Integrar o GitHub');
        $this->actingAs($this->ana)->get(route('alerts.integrations'))->assertOk()->assertSee('2 ativos')->assertSee('Adicionar monitoramento');

        // Membro de uma empresa sem monitoramento: nada para integrar, só quem configura (Designer, rodada 8).
        $eva = $this->companyAccount(Company::query()->where('name', 'Beta')->sole());
        $this->actingAs($eva)->get(route('alerts.integrations'))->assertOk()->assertDontSee('Integrar o GitHub')
            ->assertSee('Quem configura o GitHub é quem administra a empresa ou quem responde por um setor.');
    }

    // E5b1: o achado do TI sai pelos canais do TI, saia ou não quem criou do setor (fim do "GitHub mudo"); a conversa
    // pessoal de quem criou não recebe nada da empresa; a empresa suspensa não manda nada (D7).
    public function test_the_finding_goes_to_the_sector_channels_whoever_created_the_monitor(): void
    {
        $personal = NotificationChannel::query()->create(['user_id' => $this->bruno->id, 'type' => 'discord', 'method' => 'dm', 'status' => 'active', 'realtime' => true, 'digest' => false, 'timezone' => 'America/Sao_Paulo']);
        $ti = $this->companyChannel($this->bruno, $this->acme, $this->ti);
        $general = $this->companyChannel($this->ana, $this->acme);

        $this->ti->users()->detach($this->bruno->id);
        $this->refreshVisibility();
        app(GithubAlertRouter::class)->route($this->tiFinding);
        $this->assertSame('queued', $this->tiFinding->fresh()->status);
        $this->assertSame([$ti->id], DB::table('notification_deliveries')->pluck('channel_id')->all(), 'só o canal do TI: nem o geral (D5) nem a DM de quem criou');
        $this->assertNotContains($personal->id, DB::table('notification_deliveries')->pluck('channel_id')->all());
        $this->assertNotContains($general->id, DB::table('notification_deliveries')->pluck('channel_id')->all());

        app(Companies::class)->suspend($this->acme);
        // O Visibility guarda quem a conta é por requisição (scoped): a suspensão vale no próximo job.
        $this->refreshVisibility();
        app(GithubAlertRouter::class)->route($this->tiFinding->fresh());
        $this->assertSame('company_paused', $this->tiFinding->fresh()->note);
        $this->assertSame(1, DB::table('notification_deliveries')->count());
    }
}
