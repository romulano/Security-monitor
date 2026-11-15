<?php

namespace Tests\Feature\Alerts;

use App\Jobs\MatchCaseToProfiles;
use App\Jobs\SendWebhookMessage;
use App\Models\AlertMatch;
use App\Models\EmailMessage;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Alerts\Notifier;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Webhook\MessageBuilder;
use App\Services\Alerts\Webhook\WebhookClient;
use App\Services\Alerts\Webhook\WebhookExample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\WebhookReceiver;
use Tests\TestCase;

/** Webhook: o JSON do contrato, os cabeçalhos do Security Monitor, as travas de SSRF, as novas tentativas e a parada pelas falhas. */
class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase, WebhookTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        $this->fakeDns();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function alertFor(User $user, string $title = 'Nginx RCE'): AlertMatch
    {
        $this->profile($user, ['language' => 'pt'], ['nginx']);
        $case = $this->newsCase($title, 'critical', ['affected_products' => ['nginx 1.24'], 'cves' => ['CVE-2026-1']], ['title_pt' => "{$title} em português"]);

        MatchCaseToProfiles::dispatchSync($case->id, $case->articles()->value('id'));

        return AlertMatch::query()->where('case_id', $case->id)->sole();
    }

    /** Uma entrega na fila, para rodar o job à mão. */
    private function queued(NotificationChannel $webhook, string $kind = 'realtime'): NotificationDelivery
    {
        return NotificationDelivery::query()->create([
            'channel_id' => $webhook->id, 'user_id' => $webhook->user_id, 'kind' => $kind, 'status' => 'queued',
            'payload' => ['version' => 1, 'id' => (string) str()->uuid(), 'event' => 'alert.match', 'data' => ['title' => 'x']],
        ]);
    }

    public function test_an_alert_reaches_the_webhook_as_signed_json_in_the_contract_format(): void
    {
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('{"ok":true}', 202)]);
        $user = $this->client();
        $webhook = $this->webhook($user);
        $secret = (string) $webhook->webhook_secret;

        $match = $this->alertFor($user);

        $this->assertSame('sent', $match->refresh()->delivery_status);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($secret) {
            $json = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === self::HOOK
                && $request->header('User-Agent')[0] === 'SecurityMonitorBot/1.0 (+https://example.com)'
                && $request->header('Content-Type')[0] === 'application/json; charset=utf-8'
                && $request->header('Security-Monitor-Event')[0] === 'alert.match'
                && $request->header('Security-Monitor-Delivery')[0] === $json['id']
                && $request->header('Security-Monitor-Notification')[0] === $secret
                && WebhookReceiver::accepts($request, $secret)
                && ! WebhookReceiver::accepts($request, 'secmon_whsec_'.str_repeat('x', 43))
                && $json['version'] === 1
                && preg_match('/^[0-9a-f-]{36}$/', $json['id']) === 1
                && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $json['sent_at']) === 1
                && array_keys($json) === ['version', 'id', 'event', 'sent_at', 'data'];
        });

        // O contrato: os mesmos campos do exemplo documentado (tests/fixtures/webhook/alert.match.json).
        $sent = json_decode(Http::recorded()[0][0]->body(), true);
        $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/webhook/alert.match.json')), true);
        $this->assertSame(WebhookReceiver::shape($fixture)['data']['case'], WebhookReceiver::shape($sent)['data']['case']);
        $this->assertSame(['alert.match', 'critical', 'new_case', 'pt', 'Nginx RCE em português'], [$sent['event'], $sent['data']['criticity'], $sent['data']['kind'], $sent['data']['language'], $sent['data']['title']]);
        $this->assertSame(['CVE-2026-1'], $sent['data']['case']['cves']);
        $this->assertSame(['nginx 1.24'], $sent['data']['case']['products']);
        $this->assertStringContainsString('/nao-relevante?', $sent['data']['links']['irrelevant']);
        $this->assertStringContainsString('signature=', $sent['data']['links']['relevant']);
        $this->assertSame(route('feed.show', $match->case->uuid), $sent['data']['links']['case']);

        // O registro guarda o JSON (sem o segredo) e o resultado, com o código e o tempo.
        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(['sent', 1, 202, '[CRITICAL] Nginx RCE em português'], [$delivery->status, $delivery->attempts, $delivery->response_code, $delivery->summary]);
        $this->assertNotNull($delivery->duration_ms);
        $this->assertStringNotContainsString($secret, json_encode($delivery->getAttributes()));
    }

    public function test_the_payload_matches_the_fixture_whatever_the_ai_says(): void
    {
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('', 204)]);
        $user = $this->client();
        $this->webhook($user);
        $match = $this->alertFor($user);
        $match->forceFill(['ai_reviewed_at' => now(), 'ai_relevance' => 88, 'ai_reason' => 'Afeta o nginx da sua stack.'])->save();

        $payload = app(MessageBuilder::class)->alert($match->refresh());
        $stamped = WebhookClient::stamp($payload, now());
        $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/webhook/alert.match.json')), true);

        $this->assertSame(WebhookReceiver::shape($fixture), WebhookReceiver::shape(json_decode(WebhookClient::encode($stamped), true)));
        $this->assertSame(['relevance' => 88, 'reason' => 'Afeta o nginx da sua stack.'], $stamped['data']['ai_review']);
        // E o exemplo da tela também (a documentação não descola do envio).
        $this->assertSame(WebhookReceiver::shape($fixture), WebhookReceiver::shape(WebhookExample::payload()));
    }

    public function test_without_authentication_the_auth_headers_are_left_out(): void
    {
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('', 204)]);
        $webhook = $this->webhook($this->client(), ['webhook_secret' => null, 'webhook_secret_at' => null]);

        app(Notifier::class)->test($webhook);

        Http::assertSent(fn (Request $request) => $request->header('Security-Monitor-Event')[0] === 'test'
            && $request->header('User-Agent')[0] === 'SecurityMonitorBot/1.0 (+https://example.com)'
            && ! $request->hasHeader('Security-Monitor-Notification')
            && ! $request->hasHeader('Security-Monitor-Signature'));
    }

    public function test_the_connection_is_pinned_to_the_checked_ip_without_following_redirects(): void
    {
        $this->assertSame([
            'allow_redirects' => false,
            'stream' => true,
            'curl' => [CURLOPT_RESOLVE => ['hooks.cliente.com.br:443:93.184.215.14']],
        ], WebhookClient::options('hooks.cliente.com.br', self::PUBLIC_IP));
        $this->assertSame(['hooks.cliente.com.br:443:[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'], WebhookClient::options('hooks.cliente.com.br', '2606:2800:21f:cb07:6820:80da:af6b:8b2c')['curl'][CURLOPT_RESOLVE]);

        // Um 3xx é falha, sem nova tentativa e sem seguir o Location.
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);
        $webhook = $this->webhook($this->client());
        $delivery = $this->queued($webhook);

        (new SendWebhookMessage($delivery->id))->handle(app(WebhookClient::class));

        Http::assertSentCount(1);
        $this->assertSame(['failed', 1, 302], [$delivery->refresh()->status, $delivery->attempts, $delivery->response_code]);
        $this->assertStringContainsString('não segue redirecionamentos', $delivery->error);
    }

    public function test_internal_and_own_addresses_are_never_called(): void
    {
        Http::fake();
        config(['secmon.own_addresses' => ['203.0.113.10', '2001:db8:66:21cf::/64']]);
        $webhook = $this->webhook($this->client());

        foreach ([
            ['10.0.0.5'], ['127.0.0.1'], ['169.254.169.254'], ['fd00::1'], ['::ffff:10.0.0.1'], ['100.64.0.1'],
            ['203.0.113.10'], ['2001:db8:66:21cf::10'],
            // Rebinding: um nome que devolve um IP público e um interno não sai.
            [self::PUBLIC_IP, '192.168.0.10'],
        ] as $ips) {
            $this->dns->set('hooks.cliente.com.br', 'ips', $ips);
            $delivery = $this->queued($webhook, 'test');

            (new SendWebhookMessage($delivery->id))->handle(app(WebhookClient::class));

            $this->assertSame('failed', $delivery->refresh()->status, implode(',', $ips));
            $this->assertStringContainsString('aponta para um endereço que o Security Monitor não chama', $delivery->error);
        }

        Http::assertNothingSent();
    }

    public function test_only_5xx_and_network_errors_are_retried_and_429_waits(): void
    {
        $webhook = $this->webhook($this->client());
        $delivery = $this->queued($webhook);
        $this->assertSame([60, 300, 1800], (new SendWebhookMessage(1))->backoff());

        Http::fake(['https://hooks.cliente.com.br/*' => Http::sequence()
            ->push('', 503)
            ->push('', 429, ['Retry-After' => '7'])
            ->push('', 429, ['Retry-After' => '86400'])
            ->push('', 204)
            ->push('', 401)
            ->pushFailedConnection('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received for https://hooks.cliente.com.br/secmon?token=segredo')]);

        try {
            (new SendWebhookMessage($delivery->id))->withFakeQueueInteractions()->handle(app(WebhookClient::class));
            $this->fail('503 deveria lançar para a fila tentar de novo');
        } catch (RuntimeException $e) {
            $this->assertSame('Webhook: server', $e->getMessage());
            $this->assertSame('queued', $delivery->refresh()->status);
        }

        $job = (new SendWebhookMessage($delivery->id))->withFakeQueueInteractions();
        $job->handle(app(WebhookClient::class));
        $job->assertReleased(7);

        // Retry-After longo demais espera o teto da configuração (30 min).
        $job = (new SendWebhookMessage($delivery->id))->withFakeQueueInteractions();
        $job->handle(app(WebhookClient::class));
        $job->assertReleased(1800);

        (new SendWebhookMessage($delivery->id))->handle(app(WebhookClient::class));
        $this->assertSame(['sent', 4], [$delivery->refresh()->status, $delivery->attempts]);

        // 4xx não repete; a rede fora do ar, sim.
        $refused = $this->queued($webhook);
        (new SendWebhookMessage($refused->id))->handle(app(WebhookClient::class));
        $this->assertSame(['failed', 1, 401], [$refused->refresh()->status, $refused->attempts, $refused->response_code]);

        $offline = $this->queued($webhook);

        try {
            (new SendWebhookMessage($offline->id))->handle(app(WebhookClient::class));
            $this->fail('tempo esgotado deveria tentar de novo');
        } catch (RuntimeException $e) {
            // A mensagem da fila (e do log) nunca leva a URL, que pode ter um token.
            $this->assertSame('Webhook: timeout', $e->getMessage());
        }

        $this->assertStringNotContainsString('token=segredo', (string) $offline->refresh()->error);
    }

    public function test_five_failed_deliveries_in_a_row_stop_the_webhook_and_email_the_owner(): void
    {
        $status = 500;
        Http::fake(['https://hooks.cliente.com.br/*' => function () use (&$status) {
            return Http::response('', $status);
        }]);
        $user = $this->client(['name' => 'Rômulo', 'email' => 'romulo@cliente.com.br']);
        $webhook = $this->webhook($user);

        // O teste que falha não conta: é como o cliente confere o servidor enquanto configura.
        (new SendWebhookMessage($this->queued($webhook, 'test')->id))->failed(new RuntimeException('Webhook: server'));
        $this->assertSame(0, $webhook->refresh()->failures);

        foreach (range(1, 4) as $i) {
            (new SendWebhookMessage($this->queued($webhook)->id))->failed(new RuntimeException('Webhook: server'));
        }

        $this->assertSame(['active', 4], [$webhook->refresh()->status, $webhook->failures]);

        // Uma entrega feita zera a conta.
        $status = 204;
        (new SendWebhookMessage($this->queued($webhook)->id))->handle(app(WebhookClient::class));
        $this->assertSame(0, $webhook->refresh()->failures);

        foreach (range(1, 5) as $i) {
            $delivery = $this->queued($webhook);
            $delivery->forceFill(['error' => 'O seu servidor respondeu com erro (HTTP 500).'])->save();
            (new SendWebhookMessage($delivery->id))->failed(new RuntimeException('Webhook: server'));
        }

        $webhook->refresh();
        $this->assertSame(['broken', 5], [$webhook->status, $webhook->failures]);
        $this->assertStringContainsString('5 entregas seguidas falharam', $webhook->last_error);

        $mail = EmailMessage::query()->sole();
        $this->assertSame(['webhook', 'romulo@cliente.com.br', 'Security Monitor: o Webhook Produção parou de receber os alertas'], [$mail->kind, $mail->to_address, $mail->subject]);

        // Mais falhas não mandam outro e-mail, e o alerta seguinte não sai.
        (new SendWebhookMessage($this->queued($webhook)->id))->failed(new RuntimeException('Webhook: server'));
        $this->assertSame(1, EmailMessage::query()->count());
        $match = $this->alertFor($user);
        $this->assertSame(['skipped', 'channel_broken'], [$match->refresh()->delivery_status, $match->delivery_note]);

        // O teste que dá certo põe o webhook de volta.
        app(Notifier::class)->test($webhook);
        $this->assertSame(['active', 0], [$webhook->refresh()->status, $webhook->failures]);
    }

    public function test_changing_the_secret_stops_the_old_one_at_once(): void
    {
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('', 204)]);
        $user = $this->client();
        $webhook = $this->webhook($user);
        $old = (string) $webhook->webhook_secret;

        $this->actingAs($user)->put(route('alerts.webhooks.secret', $webhook), ['autenticacao' => 'gerar'])->assertSessionHas('status');
        $new = (string) $webhook->refresh()->webhook_secret;
        $this->assertNotSame($old, $new);

        $this->post(route('alerts.webhooks.test', $webhook))->assertSessionHas('status');

        Http::assertSent(fn (Request $request) => $request->header('Security-Monitor-Notification')[0] === $new
            && WebhookReceiver::accepts($request, $new)
            && ! WebhookReceiver::accepts($request, $old));

        // Sem autenticação: os cabeçalhos saem.
        $this->put(route('alerts.webhooks.secret', $webhook), ['autenticacao' => 'nenhuma'])->assertSessionHas('status');
        $this->assertFalse($webhook->refresh()->signs());
    }

}
