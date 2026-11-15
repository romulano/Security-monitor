<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\DomainFinding;
use App\Models\NewsCase;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Sector;
use App\Models\TechCatalog;
use App\Models\User;
use App\Services\Alerts\AlertRouter;
use App\Services\Alerts\IntegrationsPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E2c1 (R4, R14, §6 do plano; DESENHO-5.2 §6.1): Notícias e a caixa de alertas pelo Visibility. Cada conta vê só o
 * do seu setor e o sem setor; vota no que vê; reenvia, mexe nas sugestões, pausa, apaga e entra na entrevista só no
 * que configura. Outro setor e outra empresa: 404.
 */
class NewsVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private Sector $ti;

    private Sector $fin;

    /** @var array<string, User> */
    private array $who = [];

    /** @var array<string, AlertProfile> */
    private array $profiles = [];

    /** @var array<string, AlertMatch> */
    private array $matches = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $ana] = $this->activeCompany('Acme', 'ana@acme.com');
        [$beta, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->fin = $this->companySector($this->acme, 'Financeiro');
        $this->who = [
            'ana' => $ana,
            'bia' => $this->companyAccount($this->acme, CompanyRole::SectorManager),
            'caio' => $this->companyAccount($this->acme),
            'paulo' => $paulo,
            'pedro' => User::factory()->create(),
        ];
        $this->joinSector($this->who['bia'], $this->ti, manager: true);
        $this->joinSector($this->who['caio'], $this->ti);

        foreach (['ti' => [$this->who['bia'], $this->acme, $this->ti], 'geral' => [$ana, $this->acme, null], 'fin' => [$ana, $this->acme, $this->fin], 'beta' => [$paulo, $beta, null], 'pessoal' => [$this->who['pedro'], null, null]] as $key => [$owner, $company, $sector]) {
            $profile = $this->profileOf($owner, $company, $sector);
            $profile->forceFill(['name' => 'Alerta '.$key])->save();
            $this->profiles[$key] = $profile;
            $case = NewsCase::query()->create(['title' => 'Caso do alerta '.$key, 'fingerprint' => Str::random(32), 'is_published' => true]);
            $this->matches[$key] = AlertMatch::query()->create(['profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'criticity' => 'high', 'score' => 60, 'delivery_status' => 'sent', 'matched_at' => now()]);
        }
    }

    /** O que cada conta vê nas telas: a caixa, Notícias e a Integração. */
    public function test_the_screens_show_only_what_the_account_sees(): void
    {
        $sees = ['caio' => ['ti', 'geral'], 'bia' => ['ti', 'geral'], 'ana' => ['ti', 'geral', 'fin'], 'paulo' => ['beta'], 'pedro' => ['pessoal']];

        foreach ($sees as $who => $keys) {
            $inbox = $this->actingAs($this->who[$who])->get(route('alerts.index'))->assertOk();
            $news = $this->actingAs($this->who[$who])->get(route('alerts.news'))->assertOk();
            $this->actingAs($this->who[$who])->get(route('alerts.integrations'))->assertOk();
            // A Integração mostra os números; os alertas por trás deles são estes.
            $ids = collect($keys)->map(fn ($key) => $this->profiles[$key]->id)->sort()->values()->all();
            $this->assertSame($ids, app(IntegrationsPanel::class)->page($this->who[$who])['profiles']->pluck('id')->sort()->values()->all(), $who);

            foreach (array_keys($this->profiles) as $key) {
                $method = in_array($key, $keys, true) ? 'assertSee' : 'assertDontSee';
                $inbox->{$method}('Caso do alerta '.$key);
                $news->{$method}('Caso do alerta '.$key);
            }
        }
    }

    public function test_whoever_sees_votes_and_nobody_else(): void
    {
        $vote = fn (string $who, string $key) => $this->actingAs($this->who[$who])->post(route('alerts.feedback', $this->matches[$key]), ['feedback' => 'irrelevant']);

        $vote('caio', 'ti')->assertRedirect();
        $vote('caio', 'geral')->assertRedirect();
        $this->assertSame(['irrelevant', 'irrelevant'], [$this->matches['ti']->fresh()->feedback, $this->matches['geral']->fresh()->feedback]);

        foreach ([['caio', 'fin'], ['caio', 'beta'], ['paulo', 'fin'], ['pedro', 'fin'], ['bia', 'pessoal'], ['ana', 'beta']] as [$who, $key]) {
            $vote($who, $key)->assertNotFound();
            $this->assertNull($this->matches[$key]->fresh()->feedback, "{$who} votou em {$key}");
        }
        $vote('paulo', 'ti')->assertNotFound();
        $vote('pedro', 'geral')->assertNotFound();
    }

    public function test_only_who_configures_resends_and_decides_suggestions(): void
    {
        $tech = TechCatalog::query()->firstOrCreate(['slug' => 'wordpress'], ['name' => 'WordPress', 'category' => 'cms']);
        $findings = collect($this->profiles)->map(function (AlertProfile $profile) use ($tech) {
            $domain = $profile->domains()->create(['user_id' => $profile->user_id, 'domain' => Str::lower(Str::random(8)).'.com.br', 'verify_token' => Str::random(32)]);

            return DomainFinding::query()->create(['domain_id' => $domain->id, 'tech_id' => $tech->id, 'label' => 'WordPress', 'evidence' => 'meta']);
        });
        $resend = fn (string $who, string $key) => $this->actingAs($this->who[$who])->post(route('alerts.resend', $this->matches[$key]));
        $decide = fn (string $who, string $key) => $this->actingAs($this->who[$who])->post(route('alerts.finding.decide', [$findings[$key], 'ignorar']));

        // Membro vê e não configura; responsável não configura o que é da empresa toda nem de outro setor (P1).
        foreach ([['caio', 'ti'], ['bia', 'geral'], ['bia', 'fin'], ['paulo', 'ti'], ['pedro', 'ti'], ['ana', 'beta']] as [$who, $key]) {
            $resend($who, $key)->assertNotFound();
            $decide($who, $key)->assertNotFound();
        }

        // Quem configura chega na regra de sempre (sem app conectado, o reenvio é recusado com o aviso).
        $resend('bia', 'ti')->assertSessionHas('error');
        $resend('ana', 'fin')->assertSessionHas('error');
        $decide('bia', 'ti')->assertRedirect();
        $this->assertSame('ignored', $findings['ti']->fresh()->status);
    }

    public function test_the_alerta_parameter_only_reaches_what_the_account_configures(): void
    {
        $ti = ['alerta' => $this->profiles['ti']->id];
        $fin = ['alerta' => $this->profiles['fin']->id];

        $this->actingAs($this->who['bia'])->get(route('alerts.interview', ['passo' => 1] + $ti))->assertOk();
        $this->actingAs($this->who['caio'])->get(route('alerts.interview', ['passo' => 1] + $ti))->assertNotFound();
        $this->actingAs($this->who['bia'])->get(route('alerts.interview', ['passo' => 1] + $fin))->assertNotFound();
        $this->actingAs($this->who['paulo'])->get(route('alerts.interview', ['passo' => 1] + $ti))->assertNotFound();

        $this->actingAs($this->who['caio'])->post(route('alerts.pause', $ti))->assertNotFound();
        $this->actingAs($this->who['bia'])->post(route('alerts.pause', $fin))->assertNotFound();
        $this->actingAs($this->who['caio'])->delete(route('alerts.destroy', $ti))->assertNotFound();
        $this->actingAs($this->who['paulo'])->delete(route('alerts.destroy', $ti))->assertNotFound();
        $this->assertModelExists($this->profiles['ti']);
        $this->assertSame('active', $this->profiles['ti']->fresh()->status);

        $this->actingAs($this->who['bia'])->post(route('alerts.pause', $ti))->assertRedirect();
        $this->assertSame('paused', $this->profiles['ti']->fresh()->status);

        // O item de empresa sempre leva o ?alerta= (cada conta vê um conjunto diferente).
        $this->assertSame($ti, $this->profiles['ti']->urlParams());
    }

    public function test_company_accounts_get_no_automatic_draft_and_create_only_where_allowed(): void
    {
        // A conta pessoal ganha o rascunho, como sempre; a de empresa sem alerta que configure leva 404.
        $this->actingAs($this->who['pedro'])->get(route('alerts.interview', ['passo' => 1]))->assertOk();
        $this->actingAs($this->who['caio'])->get(route('alerts.interview', ['passo' => 1]))->assertNotFound();
        $this->assertSame(0, AlertProfile::query()->where('user_id', $this->who['caio']->id)->count());

        // "Novo alerta": a admin cria sem setor; o responsável, no setor que escolhe (E2c2); o membro não cria.
        $this->actingAs($this->who['ana'])->post(route('alerts.store'))->assertRedirect();
        $created = AlertProfile::query()->where('user_id', $this->who['ana']->id)->latest('id')->first();
        $this->assertSame([$this->acme->id, null, 'draft'], [$created->company_id, $created->sector_id, $created->status]);

        $this->actingAs($this->who['caio'])->post(route('alerts.store'))->assertSessionHas('error');
        $this->actingAs($this->who['bia'])->post(route('alerts.store'), ['setor' => $this->ti->id])->assertRedirect();
        $this->assertSame($this->ti->id, AlertProfile::query()->where('user_id', $this->who['bia']->id)->latest('id')->first()->sector_id);
        // Copiar só de um alerta que a conta vê.
        $this->actingAs($this->who['ana'])->post(route('alerts.store', ['copiar' => $this->profiles['beta']->id]))->assertNotFound();
    }

    // E5b1: o alerta do TI sai pelo canal do TI mesmo com quem o criou fora do setor; o Slack que ela tinha como conta
    // não recebe nada da empresa.
    public function test_the_alert_goes_to_the_sector_channel_even_once_its_creator_left(): void
    {
        $personal = NotificationChannel::query()->create(['user_id' => $this->who['bia']->id, 'type' => 'slack', 'method' => 'webhook', 'status' => 'active', 'webhook_url' => 'https://hooks.slack.com/services/T000/B000/abcdefghijklmnopqrstuvwx']);
        $ti = $this->companyChannel($this->who['bia'], $this->acme, $this->ti);
        $match = $this->matches['ti'];
        $match->forceFill(['delivery_status' => 'pending'])->save();

        $this->ti->users()->detach($this->who['bia']->id);
        app(AlertRouter::class)->route($match->fresh());

        $this->assertSame('pending', $match->fresh()->delivery_status);
        $this->assertSame([$ti->id], NotificationDelivery::query()->where('match_id', $match->id)->pluck('channel_id')->all());
        $this->assertFalse(NotificationDelivery::query()->where('channel_id', $personal->id)->exists());
    }
}
