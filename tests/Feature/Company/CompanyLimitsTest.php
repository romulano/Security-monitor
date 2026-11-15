<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Jobs\SendEmail;
use App\Mail\CompanyLimitNotice;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerts\AlertPanel;
use App\Services\Alerts\InterviewService;
use App\Services\Company\CompanyChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** E5d (DESENHO-5.4 §4, P7): os limites da empresa no lugar do "5 por criador", os avisos de 80%/100% e o Admin → Empresas. */
class CompanyLimitsTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private Company $acme;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
    }

    /** @return list<array{string, string, int, int, string}> os avisos de limite: [para, o quê, usado, máximo, link] */
    private function notices(): array
    {
        return Queue::pushed(SendEmail::class)->map(fn ($job) => $job->mail)->filter(fn ($mail) => $mail instanceof CompanyLimitNotice)
            ->map(fn (CompanyLimitNotice $m) => [$m->name, $m->what, $m->used, $m->max, $m->url])->values()->all();
    }

    private function newAlert(User $by): AlertProfile
    {
        return app(InterviewService::class)->create($by);
    }

    public function test_the_company_has_20_alerts_whoever_creates_them_and_the_personal_account_keeps_its_5(): void
    {
        // A admin já criou 6 (mais que os 5 de quem cria): na empresa isso não conta.
        foreach (range(1, 6) as $i) {
            $this->profileOf($this->ana, $this->acme);
        }

        foreach (range(1, 13) as $i) {
            $this->profileOf($this->companyAccount($this->acme, CompanyRole::SectorManager), $this->acme);
        }

        $this->assertTrue(app(AlertPanel::class)->canCreate($this->ana));
        $this->newAlert($this->ana);
        $this->assertSame(20, AlertProfile::query()->where('company_id', $this->acme->id)->count());
        $this->assertFalse(app(AlertPanel::class)->canCreate($this->ana));

        try {
            $this->newAlert($this->ana);
            $this->fail('passou do limite da empresa');
        } catch (ActionRefused $e) {
            $this->assertSame('A empresa chegou ao limite de 20 alertas de Notícias. Para criar outro, exclua um que não use mais. Precisa de mais? Escreva para contato@example.com.', $e->getMessage());
        }

        $this->actingAs($this->ana)->get(route('alerts.news'))->assertOk()->assertSee('data-alerts-full', false)->assertDontSee('>Novo alerta<', false);
        $this->actingAs($this->companyAccount($this->acme))->get(route('alerts.news'))->assertOk()->assertDontSee('data-alerts-full', false);

        // O admin do Security Monitor ajusta: o limite novo vale na hora.
        $this->acme->forceFill(['limit_alert_profiles' => 21])->save();
        $this->newAlert($this->ana);

        // A conta pessoal: o mesmo limite de sempre.
        $solo = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->profileOf($solo);
        }
        $this->assertFalse(app(AlertPanel::class)->canCreate($solo));
        $this->assertTrue($solo->reachedRadarLimit() === false);
    }

    public function test_alert_notices_at_80_and_100_percent_go_to_the_admin_with_the_news_link_and_come_back_after_going_down(): void
    {
        $this->acme->forceFill(['limit_alert_profiles' => 5])->save();
        $bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);

        foreach (range(1, 3) as $i) {
            $this->profileOf($this->ana, $this->acme);
        }

        $this->newAlert($this->ana);
        $this->assertSame([['Admin Acme', 'alertas de Notícias', 4, 5, route('alerts.news')]], $this->notices());

        $fifth = $this->newAlert($this->ana);
        $this->assertSame([4, 5], array_column($this->notices(), 2));

        app(AlertPanel::class)->delete($fifth);
        $this->newAlert($this->ana);
        $this->assertSame([4, 5, 5], array_column($this->notices(), 2), 'abaixo de 100% e de volta: o aviso sai outra vez');
        $this->assertNotContains($bruno->name, array_column($this->notices(), 0), 'alerta é da empresa: só a admin recebe');
    }

    public function test_channel_notices_follow_the_sector_and_the_webhooks_of_the_company(): void
    {
        $this->acme->forceFill(['limit_sector_channels' => 2, 'limit_webhooks' => 1])->save();
        $ti = $this->companySector($this->acme, 'TI');
        $rh = $this->companySector($this->acme, 'RH');
        $bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($bruno, $ti, true);
        $carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($carla, $rh, true);

        $channel = fn (string $type, $sector) => NotificationChannel::query()->forceCreate(['user_id' => $bruno->id, 'type' => $type, 'method' => $type === 'webhook' ? 'json' : 'webhook',
            'status' => 'active', 'company_id' => $this->acme->id, 'sector_id' => $sector?->id, 'webhook_url' => 'https://hooks.acme.com.br/'.uniqid(), 'webhook_host' => 'hooks.acme.com.br']);
        $record = fn (NotificationChannel $c) => app(CompanyChannels::class)->record($bruno, $c, 'channel.added');

        $record($channel('slack', $ti));
        $this->assertSame([], $this->notices(), '1 de 2: abaixo de 80%');
        $record($channel('webhook', $ti));

        $sent = collect($this->notices());
        $this->assertEqualsCanonicalizing(['Admin Acme', $bruno->name, 'Admin Acme'], $sent->pluck(0)->all(), 'o setor: a admin e quem responde por ele; os webhooks: a admin');
        $this->assertNotContains($carla->name, $sent->pluck(0)->all(), 'quem responde por outro setor não sabe dos canais deste');
        $this->assertTrue($sent->contains(fn ($n) => $n[1] === 'canais do setor TI' && $n[2] === 2 && $n[4] === route('alerts.notifiers')));
        $this->assertTrue($sent->contains(fn ($n) => $n[1] === 'webhooks' && $n[2] === 1));

        // O RH tem o marco dele.
        $record($channel('slack', $rh));
        $record($channel('slack', $rh));
        $this->assertTrue(collect($this->notices())->contains(fn ($n) => $n[0] === $carla->name && $n[1] === 'canais do setor RH'));
    }

    public function test_accounts_notice_counts_the_pending_invites_and_links_to_the_accounts_screen(): void
    {
        $this->acme->forceFill(['limit_accounts' => 5])->save();

        foreach (['a', 'b', 'c'] as $who) {
            $this->actingAs($this->ana)->post(route('company.invites.store'), ['email' => "{$who}@acme.com", 'role' => 'member'])->assertSessionHasNoErrors();
        }

        $this->assertSame([['Admin Acme', 'contas', 4, 5, route('company.accounts')]], $this->notices());
    }

    public function test_radar_has_no_creator_limit_in_the_company_and_the_admin_panel_counts_for_real(): void
    {
        foreach (range(1, 5) as $i) {
            $this->watchOf($this->ana, $this->acme);
        }

        $this->assertFalse($this->ana->fresh()->reachedRadarLimit(), 'na empresa quem limita o Radar é o domínio');
        $solo = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->watchOf($solo);
        }

        $this->assertTrue($solo->fresh()->reachedRadarLimit());

        $ti = $this->companySector($this->acme, 'TI');
        $this->companySector($this->acme, 'RH');
        $this->profileOf($this->ana, $this->acme);
        $owned = $this->acme->ownedDomains()->forceCreate(['user_id' => $this->ana->id, 'domain' => 'acme.com.br', 'source' => 'company']);
        foreach (['a', 'b', 'c'] as $name) {
            $owned->subdomains()->forceCreate(['name' => "{$name}.acme.com.br", 'origin' => 'manual', 'status' => 'active', 'first_seen_at' => now()]);
        }
        $owned->subdomains()->forceCreate(['name' => 'velho.acme.com.br', 'origin' => 'manual', 'status' => 'ignored', 'first_seen_at' => now()]);
        NotificationChannel::query()->forceCreate(['user_id' => $this->ana->id, 'type' => 'slack', 'method' => 'webhook', 'status' => 'active', 'company_id' => $this->acme->id,
            'sector_id' => $ti->id, 'webhook_url' => 'https://hooks.slack.com/services/x', 'slack_channel' => '#ti']);

        $page = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.companies.index'))->assertOk();
        $page->assertDontSee('ainda não contado');

        foreach (['Subdomínios<' => '3', 'Setores<' => '2', 'Alertas de Notícias<' => '1', 'Canais de setor<' => '1', 'Canais gerais<' => '0'] as $tile => $used) {
            $page->assertSeeInOrder([$tile, $used.' ', '/'], false);
        }
    }
}
