<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * E5a2 (DESENHO-5.4 §2.4): Notificadores da conta de empresa por escopo (gerais e setores), o que cada conta configura,
 * os webhooks da empresa pelo Visibility::manages e o teste D das rotas (outro setor, outra empresa e o membro: 404).
 */
class CompanyNotifiersTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

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
        // Bruno responde pelo TI; Caio é membro do TI.
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->caio = $this->companyAccount($this->acme);
        $this->joinSector($this->caio, $this->ti);
    }

    private function channel(User $by, ChannelScope $scope, string $type = 'slack', array $extra = []): NotificationChannel
    {
        return DB::transaction(function () use ($by, $scope, $type, $extra) {
            $channel = new NotificationChannel(NotificationChannel::defaults() + $extra + ['type' => $type, 'method' => $type === 'webhook' ? 'json' : 'webhook', 'status' => 'active',
                'webhook_url' => $type === 'webhook' ? 'https://hooks.acme.com.br/'.uniqid() : 'https://hooks.slack.com/services/T000/B000/'.str_repeat('a', 24)]);

            return tap($channel->forceFill(app(CompanyChannels::class)->claim($by, $scope, $type, $channel->method)))->save();
        });
    }

    private function general(): ChannelScope
    {
        return ChannelScope::general($this->acme->id);
    }

    private function sector(Sector $sector): ChannelScope
    {
        return ChannelScope::sector($this->acme->id, $sector->id);
    }

    public function test_each_account_sees_the_general_block_and_its_sectors_and_configures_only_where_it_may(): void
    {
        $general = $this->channel($this->ana, $this->general());
        $ti = $this->channel($this->bruno, $this->sector($this->ti), 'webhook', ['name' => 'Prod TI']);
        $rh = $this->channel($this->ana, $this->sector($this->rh), 'webhook', ['name' => 'Folha do RH']);

        // A admin: os gerais e todos os setores, e configura tudo.
        $this->actingAs($this->ana)->get(route('alerts.notifiers'))->assertOk()
            ->assertSeeInOrder(['Canais gerais', '1 de 5 canais', 'Canais do setor RH', '1 de 5 canais', 'Canais do setor TI', '1 de 5 canais'])
            ->assertSee('id="pausar-'.$general->id.'"', false)->assertSee('id="pausar-'.$rh->id.'"', false)
            ->assertSee(route('alerts.webhooks.create', ['setor' => 'geral']), false);

        // O responsável do TI: vê os gerais sem botões e configura o TI; o RH nem aparece.
        $this->refreshVisibility();
        $this->actingAs($this->bruno)->get(route('alerts.notifiers'))->assertOk()
            ->assertSeeInOrder(['Canais gerais', 'Só quem administra a empresa muda estes canais.', 'Canais do setor TI'])->assertDontSee('Canais do setor RH')->assertDontSee('Folha do RH')
            ->assertDontSee('id="pausar-'.$general->id.'"', false)->assertSee('id="pausar-'.$ti->id.'"', false)
            ->assertSee('name="tipos['.$ti->id.'][news]"', false)->assertDontSee('name="tipos['.$general->id.'][news]"', false)
            ->assertDontSee(route('alerts.webhooks.create', ['setor' => 'geral']), false)
            ->assertSee(route('alerts.webhooks.create', ['setor' => $this->ti->id]), false);

        // O membro do TI: vê onde chega cada canal, sem botões nem formulário.
        $this->refreshVisibility();
        $this->actingAs($this->caio)->get(route('alerts.notifiers'))->assertOk()
            ->assertSeeInOrder(['Canais gerais', 'Canais do setor TI'])->assertSee('Recebe:')
            ->assertSee('Você vê onde os avisos chegam. Para mudar, fale com quem administra a empresa ou com quem responde pelo setor.')
            ->assertDontSee('Pausar envio')->assertDontSee('Salvar o que cada canal recebe')->assertDontSee('Conectar:');

        // A conta pessoal: a tela de sempre.
        $this->actingAs(User::factory()->create())->get(route('alerts.notifiers'))->assertOk()->assertDontSee('Canais gerais')->assertSee('Escolha onde receber', false);
    }

    public function test_a_pending_webhook_says_what_to_do_and_a_full_block_says_how_to_free_room(): void
    {
        $pending = $this->channel($this->bruno, $this->sector($this->ti), 'webhook', ['name' => 'Prod TI', 'status' => 'pending', 'webhook_host' => 'hooks.acme.com.br']);
        foreach (range(1, 4) as $n) {
            $this->channel($this->bruno, $this->sector($this->ti), 'webhook', ['name' => 'Outro '.$n]);
        }

        $this->actingAs($this->bruno)->get(route('alerts.notifiers'))->assertOk()
            ->assertSee('Não envia até a empresa comprovar hooks.acme.com.br em')
            ->assertDontSee('button form="pausar-'.$pending->id.'"', false)
            ->assertSee('Não cabe outro canal aqui (5 de 5). Para conectar outro, desconecte um em Preferências.')
            ->assertSee(CompanyChannels::PRIVATE_REFUSED);
    }

    public function test_saving_what_each_channel_receives_only_touches_the_channels_the_account_configures(): void
    {
        $general = $this->channel($this->ana, $this->general());
        $ti = $this->channel($this->bruno, $this->sector($this->ti), 'webhook', ['name' => 'Prod TI']);
        $form = ['tipos' => [$general->id => ['news' => 0, 'radar' => 0, 'github' => 0], $ti->id => ['news' => 0, 'radar' => 1, 'github' => 1]]];

        $this->actingAs($this->caio)->put(route('alerts.center.update'), $form)->assertSessionHas('status', 'Nada mudou no que cada app recebe.');
        $this->refreshVisibility();
        $this->actingAs($this->bruno)->put(route('alerts.center.update'), $form)->assertSessionHas('status', 'Salvo. Vale a partir do próximo alerta.');

        $this->assertSame([], $general->fresh()->muted_types ?? [], 'O geral é só de quem administra.');
        $this->assertSame(['news'], $ti->fresh()->muted_types);
    }

    public function test_the_webhook_pages_open_only_for_who_configures_it(): void
    {
        $general = $this->channel($this->ana, $this->general(), 'webhook', ['name' => 'Geral']);
        $ti = $this->channel($this->bruno, $this->sector($this->ti), 'webhook', ['name' => 'Prod TI']);
        [, $paulo] = $this->activeCompany('Beta', 'paulo@beta.com');
        $routes = fn (NotificationChannel $hook) => [
            ['get', route('alerts.webhooks.show', $hook)], ['put', route('alerts.webhooks.update', $hook)],
            ['delete', route('alerts.webhooks.destroy', $hook)], ['put', route('alerts.webhooks.endpoint', $hook)],
            ['put', route('alerts.webhooks.secret', $hook)], ['post', route('alerts.webhooks.verify', $hook)],
            ['post', route('alerts.webhooks.test', $hook)],
        ];

        // Teste D: o membro (vê, não configura), o responsável no geral e a admin de outra empresa levam 404 em todas.
        foreach ([[$this->caio, $ti], [$this->bruno, $general], [$paulo, $ti], [User::factory()->create(), $ti]] as [$who, $hook]) {
            foreach ($routes($hook) as [$method, $url]) {
                $this->refreshVisibility();
                $this->actingAs($who)->{$method}($url, ['nome' => 'x', 'autenticacao' => 'nenhuma'])->assertNotFound();
            }
        }
        $this->assertNotNull($ti->fresh());
        $this->assertNotNull($general->fresh());

        $this->refreshVisibility();
        $this->actingAs($this->bruno)->get(route('alerts.webhooks.show', $ti))->assertOk()->assertSee('Prod TI');
        $this->refreshVisibility();
        $this->actingAs($this->ana)->get(route('alerts.webhooks.show', $ti))->assertOk();
    }

    public function test_a_new_webhook_says_where_it_receives_and_only_who_configures_there_creates_it(): void
    {
        [$beta] = $this->activeCompany('Beta', 'paulo@beta.com');
        $vendas = $this->companySector($beta, 'Vendas');

        // Sem dizer onde, nos gerais (responsável), no RH ou num setor de outra empresa: 404.
        foreach ([[], ['setor' => 'geral'], ['setor' => $this->rh->id], ['setor' => $vendas->id], ['setor' => 'x']] as $query) {
            $this->refreshVisibility();
            $this->actingAs($this->bruno)->get(route('alerts.webhooks.create', $query))->assertNotFound();
        }
        $this->refreshVisibility();
        $this->actingAs($this->caio)->get(route('alerts.webhooks.create', ['setor' => $this->ti->id]))->assertNotFound();

        $this->refreshVisibility();
        $this->actingAs($this->bruno)->get(route('alerts.webhooks.create', ['setor' => $this->ti->id]))->assertOk()
            ->assertSee('Este webhook é do setor TI: recebe só os avisos do que é do setor TI.')->assertSee('name="setor" value="'.$this->ti->id.'"', false);

        // O domínio da URL é da empresa, sem setor: vale para os gerais e para os setores (P1 do 5.3).
        DB::table('owned_domains')->insert(['user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'domain' => 'acme.com.br', 'source' => 'radar',
            'proof_token' => $this->acme->domain_token, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $form = ['nome' => 'Prod TI', 'url' => 'https://hooks.acme.com.br/ti', 'autenticacao' => 'nenhuma'];
        $this->refreshVisibility();
        $this->actingAs($this->caio)->post(route('alerts.webhooks.store'), $form + ['setor' => $this->ti->id])->assertNotFound();
        $this->refreshVisibility();
        $this->actingAs($this->bruno)->post(route('alerts.webhooks.store'), $form + ['setor' => 'geral'])->assertNotFound();
        $this->assertSame(0, NotificationChannel::query()->count());

        $this->refreshVisibility();
        $this->actingAs($this->bruno)->post(route('alerts.webhooks.store'), $form + ['setor' => $this->ti->id])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $hook = NotificationChannel::query()->sole();
        $this->assertSame([$this->acme->id, $this->ti->id, $this->bruno->id], [$hook->company_id, $hook->sector_id, $hook->user_id]);

        // A conta pessoal cadastra como sempre, sem setor.
        $bia = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($bia)->get(route('alerts.webhooks.create'))->assertOk()->assertDontSee('Este webhook é');
    }
}
