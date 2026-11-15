<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Jobs\SendSlackMessage;
use App\Models\AlertMatch;
use App\Models\NotificationDelivery;
use App\Services\Alerts\DigestBuilder;
use App\Services\Alerts\Notifier;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Slack\MessageBuilder;
use App\Services\Alerts\Slack\SlackClient;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Etapa 4: Slack — tempo real, falhas, silêncio, limite e resumo das 07:00. */
class SlackDeliveryTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private const HOOK = 'https://hooks.slack.com/services/T000/B000/abcdefghijklmnopqrstuvwx';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function alertFor(array $channel = [], string $criticity = 'critical', string $title = 'Nginx RCE'): AlertMatch
    {
        $user = $this->client();
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $this->slackChannel($user, $channel);
        $case = $this->newsCase($title, $criticity, ['affected_products' => ['nginx 1.24'], 'cves' => ['CVE-2026-1']], ['title_pt' => "{$title} <pt> & cia"]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        return AlertMatch::query()->where('case_id', $case->id)->sole();
    }

    public function test_realtime_alert_reaches_slack_with_the_reasons(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);

        $match = $this->alertFor();

        Http::assertSent(function (Request $request) {
            $text = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $request->url() === self::HOOK
                && $request['text'] === '[CRITICAL] Nginx RCE <pt> & cia'
                && $request['blocks'][0] == ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => 'CRITICAL · Nginx RCE <pt> & cia', 'emoji' => false]]
                && str_contains($text, 'Por que você recebeu: *Nginx')
                && str_contains($text, '/a/') && str_contains($text, 'signature=') // "Relevante" e "Não é relevante" assinados
                && str_contains($text, '"text":"Relevante"') && str_contains($text, '/relevante?') && str_contains($text, '/nao-relevante?');
        });

        $this->assertSame('sent', $match->delivery_status);
        $this->assertNotNull($match->notified_at);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(['realtime', 'sent', 1, 200], [$delivery->kind, $delivery->status, $delivery->attempts, $delivery->response_code]);
        $this->assertNotNull($delivery->channel->last_success_at);
    }

    public function test_deleted_channel_marks_the_channel_broken_without_retrying(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('channel_not_found', 404)]);

        $match = $this->alertFor();
        $delivery = NotificationDelivery::query()->sole();
        $channel = $delivery->channel;

        $this->assertSame(['failed', 1], [$delivery->status, $delivery->attempts]);
        $this->assertSame('failed', $match->delivery_status);
        $this->assertSame('broken', $channel->status);
        $this->assertStringContainsString('channel_not_found', $channel->last_error);

        // Daqui em diante nada sai, e o cliente vê o aviso para reconectar.
        $this->actingAs($channel->user)->get(route('alerts.news'))->assertSee('O Slack recusou o último envio.');
        $this->actingAs($channel->user)->get(route('alerts.slack'))->assertSee('precisa reconectar');
    }

    public function test_transient_errors_retry_and_rate_limits_wait(): void
    {
        $user = $this->client();
        $channel = $this->slackChannel($user);
        $delivery = NotificationDelivery::query()->create([
            'channel_id' => $channel->id, 'user_id' => $user->id, 'kind' => 'test', 'payload' => ['text' => 'oi'],
        ]);

        Http::fake(['hooks.slack.com/*' => Http::sequence()
            ->push('rollup_error', 500)
            ->push('rate limited', 429, ['Retry-After' => '7'])
            ->push('ok', 200)]);

        $job = (new SendSlackMessage($delivery->id))->withFakeQueueInteractions();

        try {
            $job->handle(app(SlackClient::class));
            $this->fail('erro 500 deveria lançar para a fila tentar de novo');
        } catch (RuntimeException) {
            $this->assertSame('queued', $delivery->refresh()->status);
        }

        $job = (new SendSlackMessage($delivery->id))->withFakeQueueInteractions();
        $job->handle(app(SlackClient::class));
        $job->assertReleased(7);

        (new SendSlackMessage($delivery->id))->handle(app(SlackClient::class));
        $this->assertSame(['sent', 3], [$delivery->refresh()->status, $delivery->attempts]);
        $this->assertSame([60, 300, 900], (new SendSlackMessage(1))->backoff());

        // Esgotadas as tentativas, o envio fica como falha.
        $other = NotificationDelivery::query()->create(['channel_id' => $channel->id, 'user_id' => $user->id, 'kind' => 'test', 'payload' => ['text' => 'x']]);
        (new SendSlackMessage($other->id))->failed(new RuntimeException('Slack: rollup_error'));
        $this->assertSame('failed', $other->refresh()->status);
    }

    public function test_only_slack_webhooks_are_ever_called(): void
    {
        Http::fake();
        $user = $this->client();
        $channel = $this->slackChannel($user);
        // Mesmo que o banco seja adulterado, o cliente não sai do hooks.slack.com.
        $channel->forceFill(['webhook_url' => 'http://169.254.169.254/latest/meta-data'])->save();

        $result = app(SlackClient::class)->post($channel->refresh(), ['text' => 'x']);

        $this->assertTrue($result->permanent);
        Http::assertNothingSent();

        foreach (['https://hooks.slack.com.evil.io/services/a/b/c', 'https://evil.io/hooks.slack.com/services/a/b/c', 'http://hooks.slack.com/services/T/B/C', 'https://hooks.slack.com/services/T/B/C/../../x'] as $bad) {
            $this->assertFalse(SlackClient::isWebhookUrl($bad), $bad);
        }
        $this->assertTrue(SlackClient::isWebhookUrl(self::HOOK));
    }

    public function test_connecting_a_webhook_sends_a_test_and_hides_the_secret(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $user = $this->client();
        $this->actingAs($user);

        $this->post(route('alerts.slack.webhook'), ['webhook_url' => 'https://example.com/hook'])->assertSessionHasErrors('webhook_url');
        $this->assertNull($user->slackChannel()->first());

        $this->post(route('alerts.slack.webhook'), ['webhook_url' => self::HOOK])->assertRedirect(route('alerts.slack'));

        $channel = $user->slackChannel()->firstOrFail();
        $this->assertSame(['webhook', 'active', '07:00', 'America/Sao_Paulo'], [$channel->method, $channel->status, $channel->digest_time, $channel->timezone]);
        $this->assertSame('sent', NotificationDelivery::query()->where('kind', 'test')->value('status'));

        $this->get(route('alerts.slack'))
            ->assertOk()
            ->assertSee('hooks.slack.com/…/uvwx')
            ->assertDontSee('abcdefghijklmnopqrstuvwx')
            ->assertSee('Mensagem de teste');
    }

    /** Fluxo de trabalho do Slack ("Começa com um webhook"): uma variável de texto só, `notificacao`, nada de Block Kit. */
    public function test_workflow_webhook_receives_the_whole_notification_in_one_text_variable(): void
    {
        $workflow = 'https://hooks.slack.com/triggers/T000/1234567890123/0123456789abcdef0123456789abcdef';
        Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true])]);
        $this->assertTrue(SlackClient::isWorkflowUrl($workflow));
        $this->assertFalse(SlackClient::isWorkflowUrl('https://hooks.slack.com/triggers/T000/abc/../x'));

        // Conectar: a tela ensina a variável única e o teste chega nela.
        $this->actingAs($this->client())->get(route('alerts.slack'))
            ->assertSee('Receber na sua DM com um fluxo de trabalho')
            ->assertSee('notificacao')
            ->assertDontSee('nao_relevante')
            ->assertDontSee('rodape');

        $user = $this->client();
        $this->actingAs($user)->post(route('alerts.slack.webhook'), ['webhook_url' => $workflow])->assertRedirect(route('alerts.slack'));
        Http::assertSent(fn (Request $r) => $r->url() === $workflow && array_keys($r->data()) === ['notificacao']
            && str_contains($r['notificacao'], 'Teste do Security Monitor ✅') && ! isset($r['blocks']));
        $this->get(route('alerts.slack'))->assertSee('fluxo de trabalho hooks.slack.com/…/cdef');

        // Um alerta de verdade: tudo na mesma variável, inclusive os links de "relevante" e "não é relevante".
        $user->slackChannel()->delete();
        $match = $this->alertFor(['webhook_url' => $workflow]);
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->last(fn (Request $r) => $r->url() === $workflow);
        $vars = $sent->data();
        $text = $vars['notificacao'];

        $this->assertSame(['notificacao'], array_keys($vars));
        $this->assertIsString($text);
        $this->assertStringContainsString('CRITICAL · Nginx RCE <pt> & cia', $text);
        $this->assertStringContainsString('Ler no Security Monitor: '.url('/a/materia/'.$match->case->uuid), $text);
        $this->assertStringContainsString('CVE: CVE-2026-1', $text);
        $this->assertStringContainsString('Relevante: '.url('/a/'.$match->id.'/relevante'), $text);
        $this->assertStringContainsString('Não é relevante: '.url('/a/'.$match->id.'/nao-relevante'), $text);
        $this->assertSame(2, substr_count($text, 'signature='));
        $this->assertStringNotContainsString('*', $text, 'sem marcação do Block Kit');
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringNotContainsString('alerta da sua entrevista', $text, 'sem rodapé');
        $this->assertStringNotContainsString('Ajustar alertas', $text);
        $this->assertSame('sent', NotificationDelivery::query()->where('match_id', $match->id)->value('status'));
    }

    public function test_workflow_errors_come_back_as_json(): void
    {
        $workflow = 'https://hooks.slack.com/triggers/T000/1234567890123/0123456789abcdef0123456789abcdef';
        Http::fake(['hooks.slack.com/*' => Http::response(['ok' => false, 'error' => 'invalid_workflow_input'], 400)]);
        $channel = $this->slackChannel($this->client(), ['webhook_url' => $workflow]);

        $result = app(SlackClient::class)->post($channel, ['text' => 'x', 'blocks' => []]);

        $this->assertTrue($result->permanent);
        $this->assertSame('invalid_workflow_input', $result->error);
    }

    public function test_preferences_are_validated_and_saved(): void
    {
        $user = $this->client();
        $channel = $this->slackChannel($user);
        $this->actingAs($user);

        $this->put(route('alerts.slack.update'), [
            'realtime' => '1', 'quiet' => '1', 'quiet_start' => '22:00', 'quiet_end' => '22:00',
            'digest' => '1', 'digest_time' => '07:00', 'digest_scope' => 'all', 'timezone' => 'America/Sao_Paulo',
        ])->assertSessionHasErrors('quiet_end');

        $this->put(route('alerts.slack.update'), ['digest_time' => '25:00', 'digest_scope' => 'x', 'timezone' => 'Mars/Base'])
            ->assertSessionHasErrors(['digest_time', 'digest_scope', 'timezone']);

        $this->put(route('alerts.slack.update'), [
            'realtime' => '0', 'quiet' => '1', 'quiet_start' => '21:30', 'quiet_end' => '06:00',
            'digest' => '1', 'digest_time' => '08:30', 'digest_scope' => 'profile', 'digest_days' => ['5', '1', '1'], 'timezone' => 'America/Manaus',
        ])->assertSessionHas('status');

        $channel->refresh();
        $this->assertFalse($channel->realtime);
        $this->assertSame(['21:30', '06:00', '08:30', 'profile', [1, 5], 'America/Manaus'],
            [$channel->quiet_start, $channel->quiet_end, $channel->digest_time, $channel->digest_scope, $channel->digest_days, $channel->timezone]);

        $this->delete(route('alerts.slack.destroy'))->assertRedirect(route('alerts.slack'));
        $this->assertModelMissing($channel);
    }

    public function test_quiet_hours_hold_and_flush_releases_one_or_groups_many(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        Carbon::setTestNow('2026-10-20 03:00:00'); // 00:00 em São Paulo

        $first = $this->alertFor(['quiet_start' => '22:00', 'quiet_end' => '07:00'], 'high', 'Nginx A');
        $this->assertSame(['held', 'quiet_hours'], [$first->delivery_status, $first->delivery_note]);
        $this->assertSame('2026-10-20 10:00:00', $first->release_at->utc()->format('Y-m-d H:i:s'));

        $case = $this->newsCase('Nginx B', 'high', ['affected_products' => ['nginx']]);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));
        Http::assertNothingSent();

        // Ainda no silêncio: o flush não solta nada.
        $this->artisan('secmon:alerts-flush')->assertSuccessful();
        Http::assertNothingSent();

        // 07:05 em São Paulo: os dois saem numa mensagem agrupada.
        Carbon::setTestNow('2026-10-20 10:05:00');
        $this->artisan('secmon:alerts-flush')->assertSuccessful();

        Http::assertSentCount(1);
        $grouped = NotificationDelivery::query()->sole();
        $this->assertSame(['grouped', 'sent'], [$grouped->kind, $grouped->status]);
        $this->assertSame(2, AlertMatch::query()->where('delivery_status', 'sent')->where('delivery_note', 'grouped')->count());
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'horário silencioso'));
    }

    public function test_hourly_limit_holds_the_excess(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        Settings::set('alerts.max_per_hour', 1);

        $first = $this->alertFor([], 'high', 'Nginx 1');
        $this->assertSame('sent', $first->delivery_status);

        $case = $this->newsCase('Nginx 2', 'high', ['affected_products' => ['nginx']]);
        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));
        $second = AlertMatch::query()->where('case_id', $case->id)->sole();

        $this->assertSame(['held', 'throttled'], [$second->delivery_status, $second->delivery_note]);
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($second->release_at), 1);

        $this->travel(16)->minutes();
        $this->artisan('secmon:alerts-flush');

        // Ainda acima do limite: o único segurado vai como agrupado (que não conta no limite).
        $this->assertSame('sent', $second->refresh()->delivery_status);
        $this->assertSame('grouped', NotificationDelivery::query()->latest('id')->value('kind'));
    }

    public function test_daily_digest_once_a_day_on_time_with_my_cases_first(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        Carbon::setTestNow('2026-10-20 09:30:00'); // 06:30 em São Paulo

        $this->alertFor(['digest_days' => [2]], 'critical', 'Nginx RCE'); // 20/10/2026 é terça
        $other = $this->newsCase('Outro caso grave', 'critical', ['affected_products' => ['Oracle']]);
        $this->newsCase('Caso velho', 'critical', [], ['latest_at' => now()->subDays(3)]);
        $digests = fn () => NotificationDelivery::query()->where('kind', 'digest')->count();

        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('0 resumo');
        $this->assertSame(0, $digests());

        Carbon::setTestNow('2026-10-20 10:02:00'); // 07:02
        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('1 resumo');
        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('0 resumo');

        $this->assertSame(1, $digests());
        Http::assertSent(function (Request $r) use ($other) {
            $text = json_encode($r->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return str_contains($text, 'Resumo das últimas 24 h · 20/10/2026')
                && str_contains($text, '2 casos novos ou atualizados · 2 critical')
                && str_contains($text, '*Afeta você (1)*')
                && str_contains($text, 'Nginx RCE &lt;pt&gt; &amp; cia') // mrkdwn escapado
                && str_contains($text, 'já enviado às 06:30')
                && str_contains($text, 'Panorama geral · 1 de 2 critical e high')
                && str_contains($text, $other->uuid)
                && ! str_contains($text, 'Caso velho')
                && str_contains($text, 'de ter, às 07:00 (Brasília)');
        });

        $digest = NotificationDelivery::query()->where('kind', 'digest')->sole();
        $this->assertSame('2026-10-20', $digest->digest_date->toDateString());
    }

    public function test_digest_respects_days_grace_scope_and_pause(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $match = $this->alertFor(['digest_days' => [1, 2, 3, 4, 5], 'realtime' => false]);
        $channel = $match->profile->user->slackChannel;

        Carbon::setTestNow('2026-10-25 10:30:00'); // domingo, 07:30
        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('0 resumo');

        Carbon::setTestNow('2026-10-26 15:00:00'); // segunda, 12:00: passou da tolerância de 4 h
        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('0 resumo');

        $match->profile->update(['status' => 'paused']);
        Carbon::setTestNow('2026-10-26 10:10:00');
        $this->artisan('secmon:alerts-digest')->expectsOutputToContain('0 resumo');

        $match->profile->update(['status' => 'active']);
        $channel->update(['digest_scope' => 'profile']);
        $this->artisan('secmon:alerts-digest', ['--force' => true])->expectsOutputToContain('1 resumo');

        Http::assertSent(fn (Request $r) => ! str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Panorama geral'));

        Settings::set('alerts.digest_enabled', false);
        $this->artisan('secmon:alerts-digest', ['--force' => true])->expectsOutputToContain('desligado');
    }

    public function test_empty_day_still_says_it_is_working(): void
    {
        $user = $this->client();
        $this->profile($user);
        $channel = $this->slackChannel($user);

        $payload = app(MessageBuilder::class)->digest(app(DigestBuilder::class)->build($channel, now()->startOfDay()));

        $this->assertStringContainsString('Nada novo nas últimas 24 h', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_test_message_reactivates_a_reconnected_channel(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $user = $this->client();
        $channel = $this->slackChannel($user, ['status' => 'broken', 'last_error' => 'x']);

        app(Notifier::class)->test($channel);

        $this->assertSame('active', $channel->refresh()->status);
        $this->assertNull($channel->last_error);
    }

    public function test_markdown_from_the_ai_becomes_plain_text(): void
    {
        $plain = app(MessageBuilder::class)->plain("## Resumo\n**FortiOS** tem [falha](https://x.y) grave.\n- item um\n\n\n\nfim `code`", 500);

        $this->assertSame("Resumo\nFortiOS tem falha grave.\n• item um\n\nfim code", $plain);
        $this->assertSame('abc…', app(MessageBuilder::class)->plain('abc def ghi', 5));
    }
}
