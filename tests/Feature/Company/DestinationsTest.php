<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendSlackMessage;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\NewsCase;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\AlertRouter;
use App\Services\Alerts\Notifier;
use App\Services\Company\CompanyStamp;
use App\Services\Company\DeliveryGate;
use App\Services\Company\Destinations;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E5b1 (DESENHO-5.4 §2.5): o item da empresa vai aos canais do escopo dele — o do setor só aos canais do setor, e aos
 * gerais com "copiar para os gerais" (D5, desligado por padrão); o sem setor, só aos gerais —, a saída reconfere, o
 * agrupamento nunca junta dois setores e o carimbo leva os canais antigos das contas de empresa para os gerais.
 */
class DestinationsTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $bia;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bia = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bia, $this->ti, manager: true);
    }

    private function destinations(): Destinations
    {
        return app(Destinations::class);
    }

    private function match(AlertProfile $profile, ?NewsCase $case = null): AlertMatch
    {
        $case ??= NewsCase::query()->create(['title' => 'Caso '.Str::random(6), 'fingerprint' => Str::random(32), 'is_published' => true]);

        return AlertMatch::query()->create(['profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'criticity' => 'high', 'score' => 60, 'matched_at' => now()]);
    }

    public function test_each_item_goes_only_to_the_channels_of_its_scope(): void
    {
        $general = $this->companyChannel($this->ana, $this->acme);
        $ti = $this->companyChannel($this->bia, $this->acme, $this->ti);
        $rh = $this->companyChannel($this->ana, $this->acme, $this->rh);
        $pedro = User::factory()->create();
        $personal = tap(NotificationChannel::forUser($pedro->id, 'slack')->forceFill(['method' => 'webhook', 'status' => 'active']))->save();
        $ids = fn ($item) => $this->destinations()->for($item)->pluck('id')->all();

        $this->assertSame([$ti->id], $ids($this->profileOf($this->bia, $this->acme, $this->ti)));
        $this->assertSame([$general->id], $ids($this->profileOf($this->ana, $this->acme)));
        $this->assertSame([$rh->id], $ids($this->watchOf($this->ana, $this->acme, $this->rh)));
        $this->assertSame([$personal->id], $ids($this->profileOf($pedro)), 'a conta pessoal: os canais dela, como sempre');

        // D5: com "copiar para os gerais" no TI, o do TI vai aos dois; o do RH continua só no RH.
        $this->ti->forceFill(['copy_to_general' => true])->save();
        $this->assertEqualsCanonicalizing([$ti->id, $general->id], $ids($this->monitorOf($this->bia, $this->acme, $this->ti)));
        $this->assertSame([$rh->id], $ids($this->monitorOf($this->ana, $this->acme, $this->rh)));
        $this->assertSame([$general->id], $ids($this->profileOf($this->ana, $this->acme)), 'o sem setor não vai ao setor');
    }

    // A saída relê: o item que mudou de setor (ou a cópia desligada) com a entrega na fila não sai no canal antigo.
    public function test_the_queued_delivery_checks_the_scope_again(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        Settings::set('alerts.realtime_enabled', true);
        $general = $this->companyChannel($this->ana, $this->acme);
        $ti = $this->companyChannel($this->bia, $this->acme, $this->ti);
        $this->ti->forceFill(['copy_to_general' => true])->save();
        $profile = $this->profileOf($this->bia, $this->acme, $this->ti);
        $match = $this->match($profile);

        app(AlertRouter::class)->route($match);
        $this->assertEqualsCanonicalizing([$ti->id, $general->id], NotificationDelivery::query()->pluck('channel_id')->all());

        // Na fila: desligam a cópia e o alerta vai para o RH.
        $this->ti->forceFill(['copy_to_general' => false])->save();
        $profile->forceFill(['sector_id' => $this->rh->id])->save();
        foreach (NotificationDelivery::query()->get() as $delivery) {
            app()->call([new SendSlackMessage($delivery->id), 'handle']);
        }

        $this->assertSame(['skipped'], NotificationDelivery::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(DeliveryGate::NOT_VISIBLE, app(DeliveryGate::class)->forDelivery(NotificationDelivery::query()->where('channel_id', $ti->id)->sole()));
        Http::assertNothingSent();
    }

    // Uma mensagem por destino: dois setores na mesma notícia são duas mensagens, e a lista de nomes não cruza setores.
    public function test_grouping_and_the_alert_names_never_mix_sectors(): void
    {
        $case = NewsCase::query()->create(['title' => 'Falha no X', 'fingerprint' => Str::random(32), 'is_published' => true]);
        $tiA = $this->profileOf($this->bia, $this->acme, $this->ti);
        $tiB = $this->profileOf($this->ana, $this->acme, $this->ti);
        $rh = $this->profileOf($this->ana, $this->acme, $this->rh);
        $keys = collect([$tiA, $tiB, $rh])->map(fn ($p) => $this->destinations()->key($p))->all();

        $this->assertSame($keys[0], $keys[1], 'dois criadores, o mesmo setor: o mesmo destino');
        $this->assertNotSame($keys[0], $keys[2]);
        $pedro = User::factory()->create();
        $this->assertSame('u:'.$pedro->id, $this->destinations()->key($this->profileOf($pedro)), 'a chave pessoal é a da conta');

        // A mensagem do TI cita só os alertas do TI que casaram com a notícia.
        foreach ([$tiB, $rh] as $other) {
            $this->match($other, $case)->forceFill(['delivery_status' => 'skipped', 'delivery_note' => 'merged'])->save();
        }
        $primary = $this->match($tiA, $case);
        $this->companyChannel($this->bia, $this->acme, $this->ti);
        $delivery = app(Notifier::class)->alert($primary, $this->destinations()->for($primary)->first());
        $text = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($tiB->displayName(), $text);
        $this->assertStringNotContainsString($rh->displayName(), $text);
    }

    // §2.8 e P2: só o canal da admin ativa vira geral da empresa; a conversa privada e o canal de membro, de responsável ou de
    // conta desativada saem (o Slack de quem não administra receberia tudo o que é da empresa sem setor; Red Team da E5b1).
    public function test_the_stamp_turns_only_the_admin_channels_into_general_ones(): void
    {
        $slack = NotificationChannel::query()->create(['user_id' => $this->ana->id, 'type' => 'slack', 'method' => 'webhook', 'status' => 'active']);
        $private = NotificationChannel::query()->create(['user_id' => $this->ana->id, 'type' => 'telegram', 'method' => 'private', 'telegram_chat_id' => '555', 'status' => 'active']);
        $managers = NotificationChannel::query()->create(['user_id' => $this->bia->id, 'type' => 'slack', 'method' => 'webhook', 'status' => 'active']);
        $member = $this->companyAccount($this->acme);
        $members = NotificationChannel::query()->create(['user_id' => $member->id, 'type' => 'discord', 'method' => 'webhook', 'status' => 'active']);
        $gone = $this->companyAccount($this->acme, CompanyRole::Member, ['deactivated_at' => now()]);
        $gones = NotificationChannel::query()->create(['user_id' => $gone->id, 'type' => 'telegram', 'method' => 'group', 'telegram_chat_id' => '-100', 'status' => 'active']);
        $pedro = User::factory()->create();
        $mine = NotificationChannel::query()->create(['user_id' => $pedro->id, 'type' => 'telegram', 'method' => 'private', 'telegram_chat_id' => '777', 'status' => 'active']);

        $this->assertSame(['canais' => 1, 'removidos' => 4], app(CompanyStamp::class)->channels(dryRun: true));
        $this->artisan('secmon:company-stamp')->expectsOutputToContain('Canais passados a gerais da empresa: 1; conversas privadas e canais de quem não administra que saíram: 4.')->assertSuccessful();

        $this->assertSame([$this->acme->id, null], [$slack->fresh()->company_id, $slack->fresh()->sector_id]);
        foreach ([$private, $managers, $members, $gones] as $left) {
            $this->assertModelMissing($left);
        }
        $this->assertNull($mine->fresh()->company_id, 'a conta pessoal fica como está');
        $this->assertSame(['canais' => 0, 'removidos' => 0], app(CompanyStamp::class)->channels(), 'idempotente');

        // A migration de dados faz o mesmo no deploy.
        $again = NotificationChannel::query()->create(['user_id' => $this->ana->id, 'type' => 'discord', 'method' => 'webhook', 'status' => 'active']);
        $late = NotificationChannel::query()->create(['user_id' => $this->bia->id, 'type' => 'discord', 'method' => 'webhook', 'status' => 'active']);
        (require database_path('migrations/2026_12_18_000200_stamp_company_channels.php'))->up();
        $this->assertSame($this->acme->id, $again->fresh()->company_id);
        $this->assertModelMissing($late);
        $this->assertSame(1, DB::table('notification_channels')->whereNull('company_id')->count());
    }
}
