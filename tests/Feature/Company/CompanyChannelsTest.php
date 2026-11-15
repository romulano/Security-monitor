<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Webhook\WebhookConnector;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;
use App\Services\Company\Sectors;
use App\Services\Company\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E5a1 (DESENHO-5.4 §2.3, §2.7, §2.10): os canais de aviso da empresa — o escopo (gerais ou setor), quem configura
 * (R14, P2), os limites (D1), sem conversa privada (P1), o canal como raiz da visibilidade, "meus canais" só com os
 * pessoais, a poda passando os canais à admin e o apagar setor deixando os canais gerais (P5).
 */
class CompanyChannelsTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $bruno;

    private User $caio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        // Bruno responde pelo TI; Caio é membro do RH.
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->rh);
    }

    private function channels(): CompanyChannels
    {
        return app(CompanyChannels::class);
    }

    /** Um canal como o conector grava: as colunas de dono do claim() e o resto. */
    private function channel(User $by, ChannelScope $scope, string $type = 'slack', string $method = 'webhook'): NotificationChannel
    {
        return DB::transaction(function () use ($by, $scope, $type, $method) {
            $channel = new NotificationChannel(NotificationChannel::defaults() + ['type' => $type, 'method' => $method, 'status' => 'active',
                'webhook_url' => 'https://hooks.slack.com/services/T000/B000/'.str_repeat('a', 24)]);

            return tap($channel->forceFill($this->channels()->claim($by, $scope, $type, $method)))->save();
        });
    }

    private function refused(callable $action): string
    {
        try {
            $action();
        } catch (ActionRefused|ValidationException $e) {
            return $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
        }

        $this->fail('Aceitou o que devia recusar.');
    }

    private function general(): ChannelScope
    {
        return ChannelScope::general($this->acme->id);
    }

    private function sector(Sector $sector): ChannelScope
    {
        return ChannelScope::sector($this->acme->id, $sector->id);
    }

    public function test_the_scope_is_the_personal_account_the_general_channels_or_a_sector_of_the_company(): void
    {
        $bia = User::factory()->create();
        $this->assertEquals(ChannelScope::personal($bia->id), $this->channels()->scope($bia, 'geral'));
        $this->assertEquals($this->general(), $this->channels()->scope($this->caio, 'geral'));
        $this->assertEquals($this->sector($this->ti), $this->channels()->scope($this->caio, (string) $this->ti->id));

        // Setor de outra empresa, nada ou lixo: recusa, sem dizer de quem é.
        [$beta] = $this->activeCompany('Beta', 'paulo@beta.com');
        $vendas = $this->companySector($beta, 'Vendas');
        foreach ([(string) $vendas->id, null, '1 or 1=1', '-1'] as $choice) {
            $this->assertSame('Escolha onde o canal recebe: nos gerais da empresa ou num setor.', $this->refused(fn () => $this->channels()->scope($this->caio, $choice)));
        }

        // Empresa suspensa: a conta não conecta nada.
        $this->acme->forceFill(['suspended_at' => now()])->save();
        $this->refreshVisibility();
        $this->assertSame('Esta conta não conecta canais agora.', $this->refused(fn () => $this->channels()->scope($this->caio, 'geral')));
    }

    // R14 e P2: os gerais só quem administra; o setor, quem administra e quem responde por ele; o membro, nada.
    public function test_who_configures_channels_in_each_scope(): void
    {
        $bia = User::factory()->create();
        $cases = [
            [$this->ana, $this->general(), true], [$this->ana, $this->sector($this->ti), true], [$this->ana, $this->sector($this->rh), true],
            [$this->bruno, $this->general(), false], [$this->bruno, $this->sector($this->ti), true], [$this->bruno, $this->sector($this->rh), false],
            [$this->caio, $this->general(), false], [$this->caio, $this->sector($this->rh), false],
            [$bia, ChannelScope::personal($bia->id), true], [$bia, $this->general(), false], [$this->ana, ChannelScope::personal($bia->id), false],
        ];

        foreach ($cases as [$who, $scope, $manages]) {
            $this->assertSame($manages, $this->channels()->manages($who, $scope), "{$who->email} em ".json_encode($scope));
        }

        $this->assertStringStartsWith('Só quem administra a empresa conecta canais gerais', $this->refused(fn () => $this->channel($this->bruno, $this->general())));
        $this->assertStringStartsWith('Só quem responde pelo setor', $this->refused(fn () => $this->channel($this->bruno, $this->sector($this->rh))));
        $this->assertStringStartsWith('Só quem responde pelo setor', $this->refused(fn () => $this->channel($this->caio, $this->sector($this->rh))));
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    // D1: 5 gerais, 5 por setor (todos os apps, webhooks também) e 10 webhooks na empresa; o admin do Security Monitor ajusta.
    public function test_the_company_limits_count_every_app_in_the_scope(): void
    {
        $this->acme->forceFill(['limit_general_channels' => 2, 'limit_sector_channels' => 1])->save();

        $this->channel($this->ana, $this->general());
        $this->channel($this->ana, $this->general(), 'discord', 'webhook');
        $this->assertSame('A empresa já tem 2 canais gerais, o máximo. Remova um para conectar outro.', $this->refused(fn () => $this->channel($this->ana, $this->general())));

        $this->channel($this->bruno, $this->sector($this->ti));
        $this->assertSame('O setor TI já tem 1 canais, o máximo. Remova um para conectar outro.', $this->refused(fn () => $this->channel($this->bruno, $this->sector($this->ti), 'telegram', 'group')));
        // Outro setor tem a cota dele.
        $this->channel($this->ana, $this->sector($this->rh));

        // Webhooks: a conta da empresa toda.
        $this->acme->forceFill(['limit_general_channels' => 5, 'limit_sector_channels' => 5, 'limit_webhooks' => 1])->save();
        $this->channel($this->ana, $this->general(), 'webhook', 'json');
        $this->assertSame('A empresa já tem 1 webhooks, o máximo. Para conectar outro, desconecte um em Notificadores. Precisa de mais? Escreva para contato@example.com.', $this->refused(fn () => $this->channel($this->bruno, $this->sector($this->ti), 'webhook', 'json')));
        $this->assertSame(5, NotificationChannel::query()->where('company_id', $this->acme->id)->count());
    }

    // P1 / R11: o canal da empresa nunca é a conversa de uma pessoa; o setor apagado no meio vira a mensagem.
    public function test_a_company_channel_is_never_private_and_never_lands_in_a_deleted_sector(): void
    {
        $this->assertSame(CompanyChannels::PRIVATE_REFUSED, $this->refused(fn () => $this->channel($this->ana, $this->general(), 'telegram', 'private')));
        $this->assertSame(CompanyChannels::PRIVATE_REFUSED, $this->refused(fn () => $this->channel($this->bruno, $this->sector($this->ti), 'discord', 'dm')));

        $scope = $this->sector($this->ti);
        $this->ti->delete();
        $this->assertSame('O setor foi apagado. Escolha outro.', $this->refused(fn () => $this->channel($this->ana, $scope)));

        // A segunda trava: mesmo gravado à mão como grupo, o canal da empresa não conta como conversa privada.
        $group = $this->channel($this->ana, $this->general(), 'telegram', 'group');
        $this->assertFalse($group->isPrivate());
    }

    // O canal é raiz da visibilidade: a admin vê todos; quem é do setor vê os gerais e os do setor; o pessoal, só o dono.
    public function test_who_sees_a_company_channel(): void
    {
        $general = $this->channel($this->ana, $this->general());
        $ti = $this->channel($this->bruno, $this->sector($this->ti));
        $rh = $this->channel($this->ana, $this->sector($this->rh));
        $personal = NotificationChannel::forUser($this->caio->id, 'slack');
        $personal->forceFill(['method' => 'webhook', 'status' => 'active'])->save();

        $sees = fn (User $user) => NotificationChannel::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
        $this->assertSame([$general->id, $ti->id, $rh->id], $sees($this->ana));
        $this->assertSame([$general->id, $ti->id], $sees($this->bruno));
        $this->assertSame([$general->id, $rh->id], $sees($this->caio), 'o Slack que o Caio conectou como conta (antes da E5a) não aparece: conta de empresa só vê os da empresa');

        $visibility = app(Visibility::class);
        $this->assertTrue($visibility->manages($this->bruno, $ti));
        $this->assertFalse($visibility->manages($this->bruno, $general));
        $this->assertFalse($visibility->manages($this->caio, $rh));
    }

    // Achado 3 do DESENHO-5.4: quem conectou não manda no canal; quem configura o escopo, sim.
    public function test_pausing_a_company_channel_is_for_who_configures_it_not_for_who_connected_it(): void
    {
        $ti = $this->channel($this->bruno, $this->sector($this->ti));
        $pause = fn (User $who) => $this->actingAs($who)->post(route('alerts.center.pause', $ti));

        $pause($this->caio)->assertNotFound();
        $pause($this->ana)->assertRedirect();
        $this->assertNotNull($ti->fresh()->paused_at);

        // Bruno conectou, mas deixou de responder pelo TI: não pausa mais.
        DB::table('sector_user')->where('user_id', $this->bruno->id)->update(['is_manager' => false]);
        $this->refreshVisibility();
        $pause($this->bruno)->assertNotFound();
        $this->assertNotNull($ti->fresh()->paused_at);

        // O canal pessoal continua com o dono, como sempre.
        $bia = User::factory()->create(['email_verified_at' => now()]);
        $mine = tap(NotificationChannel::forUser($bia->id, 'slack')->forceFill(['method' => 'webhook', 'status' => 'active']))->save();
        $this->actingAs($bia)->post(route('alerts.center.pause', $mine))->assertRedirect();
        $this->actingAs($this->ana)->post(route('alerts.center.pause', $mine))->assertNotFound();
    }

    // Achado 4: "meus canais" (código de segurança, aviso dos testes, Integração) nunca traz o canal da empresa.
    public function test_the_account_relations_only_bring_personal_channels(): void
    {
        $this->channel($this->ana, $this->general());
        $this->channel($this->ana, $this->general(), 'webhook', 'json');
        $this->channel($this->ana, $this->general(), 'telegram', 'group');

        $this->assertSame(0, $this->ana->notificationChannels()->count());
        $this->assertSame(0, $this->ana->webhooks()->count());
        $this->assertNull($this->ana->slackChannel()->first());
        $this->assertNull($this->ana->telegramChannel()->first());
        // O canal pessoal novo não "acha" o Slack da empresa que a mesma pessoa conectou.
        $this->assertFalse(NotificationChannel::forUser($this->ana->id, 'slack')->exists);
    }

    // §2.7: a poda passa à admin os canais da empresa que a conta conectou, a cópia da posse do webhook e os envios.
    public function test_the_purge_hands_the_company_channels_over_to_the_admin(): void
    {
        $slack = $this->channel($this->bruno, $this->sector($this->ti));
        $webhook = $this->channel($this->bruno, $this->sector($this->ti), 'webhook', 'json');
        // Removido pela admin há mais de 30 dias, depois de conectar.
        $this->memberRow($this->bruno)->forceFill(['deactivated_at' => now()->subDays(31)])->save();
        $copy = DB::table('monitored_domains')->insertGetId(['user_id' => $this->bruno->id, 'domain' => 'acme.com.br', 'verify_token' => str_repeat('b', 32), 'created_at' => now(), 'updated_at' => now()]);
        $webhook->forceFill(['domain_id' => $copy])->save();
        $sent = NotificationDelivery::query()->create(['channel_id' => $slack->id, 'user_id' => $this->bruno->id, 'kind' => 'test', 'status' => 'sent', 'payload' => []]);
        $personal = tap(NotificationChannel::forUser($this->bruno->id, 'slack')->forceFill(['method' => 'webhook', 'status' => 'active']))->save();

        $this->artisan('secmon:company-purge')->expectsOutputToContain('Apagadas: 1')->assertSuccessful();

        $this->assertModelMissing($this->bruno);
        foreach ([$slack, $webhook] as $channel) {
            $this->assertSame([$this->ana->id, $this->ti->id], [$channel->fresh()?->user_id, $channel->fresh()?->sector_id]);
        }
        $this->assertSame($this->ana->id, (int) DB::table('monitored_domains')->where('id', $copy)->value('user_id'));
        $this->assertSame($this->ana->id, $sent->fresh()?->user_id, 'o histórico de envios do canal fica');
        $this->assertModelMissing($personal);
    }

    // R11 e P5: apagar o setor faz os canais dele virarem gerais, mesmo passando do limite; o diálogo conta.
    public function test_deleting_a_sector_turns_its_channels_into_general_ones(): void
    {
        $this->acme->forceFill(['limit_general_channels' => 1])->save();
        $this->channel($this->ana, $this->general());
        $slack = $this->channel($this->bruno, $this->sector($this->ti));
        $webhook = $this->channel($this->ana, $this->sector($this->ti), 'webhook', 'json');
        $sectors = app(Sectors::class);
        $counts = $sectors->counts($this->ti);

        $this->assertSame(['alertas' => 0, 'radares' => 0, 'github' => 0, 'contas' => 1, 'dominios' => 0, 'canais' => 2], $counts);
        $this->assertSame('Nada do que se monitora está neste setor; 2 canais de aviso passam a ser gerais; 1 conta sai do setor.', Sectors::summary($counts));

        // O formulário leva a contagem em posição e o servidor confere.
        $this->actingAs($this->ana)->get(route('company.sectors'))->assertOk()
            ->assertSee('value="'.$this->ti->id.'-0-0-0-1-0-2"', false)
            ->assertSee('viram canais gerais');

        $sectors->delete($this->ana, $this->ti->id, $counts);
        foreach ([$slack, $webhook] as $channel) {
            $this->assertSame([$this->acme->id, null], [$channel->fresh()->company_id, $channel->fresh()->sector_id]);
        }
        $this->assertSame(3, $this->general()->constrain(NotificationChannel::query())->count(), 'acima do limite: só o próximo é barrado');
        $this->assertStringStartsWith('A empresa já tem 1 canais gerais', $this->refused(fn () => $this->channel($this->ana, $this->general())));
    }

    // A URL e o segredo do webhook não se repetem na empresa, sem dizer qual webhook de outro setor os usa.
    public function test_company_webhooks_do_not_repeat_and_do_not_reveal_the_other_sector(): void
    {
        DB::table('owned_domains')->insert(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'acme.com.br', 'source' => 'radar',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $connector = app(WebhookConnector::class);
        $session = $this->app['session.store'];
        $connector->create($this->ana, 'Segredo do RH', 'https://hooks.acme.com.br/rh', 'nenhuma', null, $session, $this->sector($this->rh));

        $this->assertSame('Essa URL já está em outro webhook da empresa.', $this->refused(fn () => $connector->create($this->bruno, 'Prod', 'https://hooks.acme.com.br/rh', 'nenhuma', null, $session, $this->sector($this->ti))));
        // O mesmo nome em outro escopo pode; no mesmo, não.
        $connector->create($this->bruno, 'Segredo do RH', 'https://hooks.acme.com.br/ti', 'nenhuma', null, $session, $this->sector($this->ti));
        $this->assertSame('Já existe um webhook com esse nome aqui.', $this->refused(fn () => $connector->create($this->bruno, 'segredo do rh', 'https://hooks.acme.com.br/ti2', 'nenhuma', null, $session, $this->sector($this->ti))));
        $this->assertSame(2, NotificationChannel::query()->where('company_id', $this->acme->id)->count());
    }
}
