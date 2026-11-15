<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertMatch;
use App\Models\InterviewQuestion;
use App\Models\NotificationDelivery;
use App\Models\TechCatalog;
use App\Services\Alerts\InterviewService;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\ProfilePreview;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Etapa 5: Admin → Alertas. */
class AdminAlertsTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private FakeProfileIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = new FakeProfileIndex;
        $this->app->instance(ProfileIndex::class, $this->index);
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    }

    public function test_only_admins_get_in(): void
    {
        $pages = ['admin.alerts.overview', 'admin.alerts.clients', 'admin.alerts.deliveries', 'admin.alerts.catalog', 'admin.alerts.questions'];

        foreach ($pages as $page) {
            $this->get(route($page))->assertRedirect(route('admin.login'));
        }

        $this->actingAs($this->client());

        foreach ($pages as $page) {
            $this->get(route($page))->assertForbidden();
        }

        $this->put(route('admin.alerts.config'), ['enabled' => '0'])->assertForbidden();
        $this->assertTrue(Settings::bool('alerts.enabled'));
    }

    public function test_overview_shows_health_numbers_and_issues(): void
    {
        $user = $this->client();
        $profile = $this->profile($user, [], ['nginx']);
        $this->slackChannel($user, ['status' => 'broken', 'last_error' => 'O canal não existe mais (channel_not_found).', 'broken_at' => now()]);
        $case = $this->newsCase('Nginx', 'high', ['affected_products' => ['nginx']]);
        AlertMatch::query()->create(['profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'matched_at' => now(),
            'reasons' => [['type' => 'stack', 'label' => 'Nginx', 'field' => 'affected_products', 'weight' => 60]], 'feedback' => 'irrelevant']);

        $this->actingAs($this->adminUser())->get(route('admin.alerts.overview'))
            ->assertOk()
            ->assertSee('Alertas personalizados')
            ->assertSee('Canais com problema')
            ->assertSee('channel_not_found')
            ->assertSee('100%') // 1 de 1 alerta marcado como não relevante
            ->assertSee('revisar termos');
    }

    public function test_config_is_validated_and_saved(): void
    {
        $this->actingAs($this->adminUser());

        $this->put(route('admin.alerts.config'), ['digest_default_time' => '7h', 'default_timezone' => 'Lua/Base', 'max_per_hour' => 0, 'min_score' => 200, 'discovery_every_days' => 0, 'consent_version' => 'v 2'])
            ->assertSessionHasErrors(['digest_default_time', 'default_timezone', 'max_per_hour', 'min_score', 'discovery_every_days', 'consent_version']);

        // Varredura do site do cliente: nunca mais de 1 por mês.
        $this->put(route('admin.alerts.config'), ['discovery_every_days' => 15])->assertSessionHasErrors('discovery_every_days');

        $this->put(route('admin.alerts.config'), [
            'enabled' => '1', 'realtime_enabled' => '0', 'digest_enabled' => '1', 'discovery_enabled' => '0',
            'digest_default_time' => '06:30', 'default_timezone' => 'America/Manaus', 'max_per_hour' => 5, 'min_score' => 60,
            'discovery_every_days' => 45, 'consent_version' => 'v2', 'ai_review_enabled' => '0', 'ai_review_threshold' => 80, 'github_ai_threshold' => 75,
        ])->assertSessionHas('status');

        $this->assertFalse(Settings::bool('alerts.realtime_enabled'));
        $this->assertFalse(Settings::bool('alerts.discovery_enabled'));
        $this->assertFalse(Settings::bool('alerts.ai_review_enabled'));
        $this->assertSame(80, Settings::int('alerts.ai_review_threshold'));
        $this->assertSame(75, Settings::int('github.ai_threshold'));
        $this->assertSame(['06:30', 'America/Manaus', 5, 60, 45, 'v2'], [
            Settings::get('alerts.digest_default_time'), Settings::get('alerts.default_timezone'), Settings::int('alerts.max_per_hour'),
            Settings::int('alerts.min_score'), Settings::int('alerts.discovery_every_days'), Settings::get('alerts.consent_version'),
        ]);
    }

    public function test_operational_buttons(): void
    {
        $user = $this->client();
        $profile = $this->profile($user, [], ['nginx']);
        $this->slackChannel($user);
        $this->newsCase('Nginx hoje', 'high', ['affected_products' => ['nginx']]);
        $this->actingAs($this->adminUser());

        $this->post(route('admin.alerts.run-match'))->assertSessionHas('status');
        $this->assertSame('backfill', AlertMatch::query()->sole()->delivery_note);

        $this->post(route('admin.alerts.recompile'))->assertSessionHas('status', '1 perfil(is) recompilado(s). O percolator foi regravado.');
        $this->assertArrayHasKey($profile->id, $this->index->stored);

        $this->post(route('admin.alerts.send-digests'))->assertSessionHas('status');
        $this->assertSame(1, NotificationDelivery::query()->where('kind', 'digest')->count());
    }

    public function test_clients_list_and_filters(): void
    {
        $active = $this->client(['name' => 'Ana']);
        $this->profile($active, ['company_name' => 'Acme Pagamentos'], ['nginx']);
        $this->slackChannel($active, ['status' => 'broken']);
        $draft = $this->client(['name' => 'Bruno']);
        $this->profile($draft, ['company_name' => 'Beta Saúde', 'status' => 'draft', 'completed_at' => null, 'step_reached' => 3]);
        $this->client(['name' => 'Carla']);
        $this->actingAs($this->adminUser());

        $this->get(route('admin.alerts.clients'))->assertOk()
            ->assertSee('Acme Pagamentos')->assertSee('rascunho · passo 3')->assertSee('sem perfil')->assertSee('com problema');

        $this->get(route('admin.alerts.clients', ['perfil' => 'draft']))->assertSee('Beta Saúde')->assertDontSee('Acme Pagamentos');
        $this->get(route('admin.alerts.clients', ['perfil' => 'none']))->assertSee('Carla')->assertDontSee('Beta Saúde');
        $this->get(route('admin.alerts.clients', ['canal' => 'broken']))->assertSee('Acme Pagamentos')->assertDontSee('Carla');
        $this->get(route('admin.alerts.clients', ['q' => 'acme']))->assertSee('Acme Pagamentos')->assertDontSee('Beta Saúde');
    }

    public function test_client_detail_and_admin_actions(): void
    {
        $user = $this->client();
        $profile = app(InterviewService::class)->profileFor($user);
        app(InterviewService::class)->save($profile, 1, ['company_name' => 'Acme Pagamentos', 'sectors' => ['financeiro']]);
        app(InterviewService::class)->save($profile, 3, ['web_servers' => ['nginx']]);
        app(InterviewService::class)->finish($profile->refresh());
        $this->slackChannel($user);
        $redis = TechCatalog::query()->where('slug', 'redis')->value('id');
        $this->mock(ProfilePreview::class)->shouldReceive('run')->andReturn(['total' => 4, 'by_level' => [], 'examples' => [], 'per_week' => 1.0, 'error' => null]);

        $this->actingAs($this->adminUser());

        $this->get(route('admin.alerts.client', $user))->assertOk()
            ->assertSee('Acme Pagamentos')
            ->assertSee('Financeiro')
            ->assertSee('"nginx"')
            ->assertSee('Rodar prévia de 30 dias');
        $this->get(route('admin.alerts.client', [$user, 'previa' => 1]))->assertSee('4 alertas nos últimos 30 dias');

        $this->post(route('admin.alerts.client.tech', $user), ['tech_id' => $redis, 'action' => 'add'])->assertSessionHas('status');
        $this->assertSame('admin', $profile->techs()->where('tech_catalog.id', $redis)->first()->pivot->source);
        $this->assertStringContainsString('"redis"', $profile->refresh()->match_query);

        // Refazer a entrevista não apaga o que o admin acrescentou.
        app(InterviewService::class)->save($profile, 3, ['web_servers' => ['apache']]);
        $this->assertTrue($profile->techs()->where('tech_catalog.id', $redis)->exists());

        $this->post(route('admin.alerts.client.tech', $user), ['tech_id' => $redis, 'action' => 'remove']);
        $this->assertStringNotContainsString('"redis"', $profile->refresh()->match_query);

        $this->post(route('admin.alerts.client.pause', $user));
        $this->assertTrue($profile->refresh()->isPaused());
        $this->assertArrayNotHasKey($profile->id, $this->index->stored);

        $this->post(route('admin.alerts.client.test', $user))->assertSessionHas('status');
        $this->assertSame('sent', NotificationDelivery::query()->where('kind', 'test')->value('status'));
    }

    public function test_deliveries_list_payload_and_retry(): void
    {
        $user = $this->client();
        $this->profile($user, ['company_name' => 'Acme']);
        $channel = $this->slackChannel($user);
        $failed = NotificationDelivery::query()->create(['channel_id' => $channel->id, 'user_id' => $user->id, 'kind' => 'realtime',
            'status' => 'failed', 'summary' => '[HIGH] Falha X', 'error' => 'Falha ao falar com o Slack (http_500).', 'payload' => ['text' => 'oi']]);
        $sent = NotificationDelivery::query()->create(['channel_id' => $channel->id, 'user_id' => $user->id, 'kind' => 'digest',
            'status' => 'sent', 'summary' => '42 casos', 'digest_date' => now()->toDateString(), 'payload' => ['text' => 'resumo']]);
        $this->actingAs($this->adminUser());

        $this->get(route('admin.alerts.deliveries'))->assertOk()->assertSee('[HIGH] Falha X')->assertSee('42 casos')->assertSee('Reenviar');
        $this->get(route('admin.alerts.deliveries', ['status' => 'failed']))->assertSee('[HIGH] Falha X')->assertDontSee('42 casos');
        $this->get(route('admin.alerts.deliveries', ['payload' => $sent->id]))->assertSee('&quot;text&quot;: &quot;resumo&quot;', false);

        $this->post(route('admin.alerts.deliveries.retry', $sent))->assertSessionHas('error');

        $channel->update(['status' => 'broken']);
        $this->post(route('admin.alerts.deliveries.retry', $failed))->assertSessionHas('error');

        $channel->update(['status' => 'active']);
        $this->post(route('admin.alerts.deliveries.retry', $failed))->assertSessionHas('status');
        $this->assertSame('sent', $failed->refresh()->status);
    }

    public function test_catalog_edit_recompiles_the_profiles_that_use_it(): void
    {
        $profile = $this->profile(null, [], ['nginx']);
        app(InterviewService::class)->recompile($profile);
        $nginx = TechCatalog::query()->where('slug', 'nginx')->firstOrFail();
        $this->actingAs($this->adminUser());

        $this->get(route('admin.alerts.catalog', ['editar' => $nginx->id]))->assertOk()->assertSee('Editar · Nginx')->assertSee('header:server:re:nginx');

        $this->put(route('admin.alerts.catalog.update', $nginx), [
            'name' => 'Nginx', 'category' => 'webserver', 'terms' => "nginx\nopenresty, ", 'detect' => "header:server:re:nginx(?:/([\\d.]+))?\ncookie:x",
            'strict' => '0', 'active' => '1',
        ])->assertSessionHas('status', 'Nginx salva. 1 perfil(is) recompilado(s).');

        $this->assertSame(['nginx', 'openresty'], $nginx->refresh()->terms);
        $this->assertSame(['type' => 'cookie', 'pattern' => 'x'], $nginx->detect[1]);
        $this->assertStringContainsString('"openresty"', $profile->refresh()->match_query);

        $this->put(route('admin.alerts.catalog.update', $nginx), ['name' => 'Nginx', 'category' => 'webserver', 'terms' => 'nginx', 'detect' => 'banner:nginx'])
            ->assertSessionHasErrors('detect');
        $this->put(route('admin.alerts.catalog.update', $nginx), ['name' => 'Nginx', 'category' => 'webserver', 'terms' => 'nginx', 'detect' => 'html:re:(unclosed'])
            ->assertSessionHasErrors('detect');

        $this->post(route('admin.alerts.catalog.toggle', $nginx));
        $this->assertFalse($nginx->refresh()->active);
        $this->assertStringNotContainsString('"nginx"', $profile->refresh()->match_query, 'tecnologia desativada sai da busca');

        $this->post(route('admin.alerts.catalog.store'), ['name' => 'Bun', 'slug' => 'bun', 'category' => 'language', 'terms' => 'bun runtime', 'active' => '1'])
            ->assertRedirect();
        $this->assertSame(['bun runtime'], TechCatalog::query()->where('slug', 'bun')->value('terms'));
        $this->post(route('admin.alerts.catalog.store'), ['name' => 'Bun', 'slug' => 'bun', 'category' => 'language', 'terms' => 'x'])->assertSessionHasErrors('slug');
    }

    public function test_questions_can_be_added_edited_moved_and_feed_the_profile(): void
    {
        $this->actingAs($this->adminUser());

        $this->post(route('admin.alerts.questions.store'), [
            'key' => 'erp', 'prompt' => 'Qual ERP vocês usam?', 'step' => 4, 'kind' => 'catalog', 'catalog_category' => 'tool', 'active' => '1',
        ])->assertRedirect();
        $this->post(route('admin.alerts.questions.store'), ['key' => 'outros', 'prompt' => 'Mais termos', 'step' => 4, 'kind' => 'tags', 'active' => '1']);
        $this->post(route('admin.alerts.questions.store'), ['key' => 'vazia', 'prompt' => 'Sem opções', 'step' => 4, 'kind' => 'multi'])
            ->assertSessionHasErrors('options');

        // Pergunta de sistema: só texto, passo e se aparece.
        $level = InterviewQuestion::query()->where('key', 'min_criticity')->firstOrFail();
        $this->put(route('admin.alerts.questions.update', $level), ['prompt' => 'Nível mínimo', 'step' => 6, 'kind' => 'text', 'active' => '1'])
            ->assertSessionHasErrors('kind');
        $this->put(route('admin.alerts.questions.update', $level), ['prompt' => 'Nível mínimo', 'step' => 6, 'required' => '1', 'active' => '1'])
            ->assertSessionHas('status');
        $this->assertSame(['Nível mínimo', 'single'], [$level->refresh()->prompt, $level->kind]);
        $this->delete(route('admin.alerts.questions.destroy', $level))->assertSessionHas('error');

        // Subir a pergunta nova para antes de "custom_terms".
        $erp = InterviewQuestion::query()->where('key', 'erp')->firstOrFail();
        $this->post(route('admin.alerts.questions.move', $erp), ['direcao' => 'cima']);
        $keys = InterviewQuestion::query()->where('step', 4)->orderBy('position')->pluck('key')->all();
        $this->assertSame(['edge', 'identity', 'platforms', 'tools', 'endpoints', 'erp', 'custom_terms', 'outros'], $keys);

        // O cliente responde e as respostas viram tecnologia e termo.
        $client = $this->client();
        $this->actingAs($client)->get(route('alerts.interview', 4))->assertSee('Qual ERP vocês usam?');
        $this->put(route('alerts.interview.update', 4), ['answers' => ['erp' => ['totvs'], 'outros' => ['Protheus 12']]]);

        $profile = $client->alertProfile;
        $this->assertContains('totvs', $profile->techs()->pluck('slug')->all());
        $this->assertContains('Protheus 12', $profile->terms()->pluck('value')->all());

        $this->actingAs($this->adminUser())->delete(route('admin.alerts.questions.destroy', $erp))->assertRedirect(route('admin.alerts.questions'));
        $this->assertModelMissing($erp);
    }
}
