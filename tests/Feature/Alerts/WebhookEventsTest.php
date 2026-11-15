<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertMatch;
use App\Models\GithubMonitor;
use App\Models\LookalikeAlert;
use App\Models\LookalikeWatch;
use App\Services\Alerts\Notifier;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Webhook\MessageBuilder;
use App\Services\Alerts\Webhook\WebhookClient;
use App\Services\Alerts\Webhook\WebhookExample;
use App\Services\Alerts\Webhook\WebhookSecret;
use App\Services\Github\GithubMessages;
use App\Services\Lookalike\AlertMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\WebhookSnippetRunner;
use Tests\TestCase;

/** Os outros eventos do webhook (agrupado, resumo, Radar, GitHub, testes, teste) e os exemplos de conferência da tela. */
class WebhookEventsTest extends TestCase
{
    use RefreshDatabase, WebhookTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        $this->fakeDns();
        Http::fake(['https://hooks.cliente.com.br/*' => Http::response('', 204)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** O JSON da última entrega no webhook. */
    private function lastSent(): array
    {
        return json_decode(collect(Http::recorded())->last()[0]->body(), true);
    }

    public function test_grouped_and_digest_go_as_their_own_events(): void
    {
        // 05:00 em Brasília: dentro da janela do resumo das 07:00 de hoje.
        Carbon::setTestNow(Carbon::parse('2026-11-03 08:00:00', 'UTC'));
        $user = $this->client();
        $webhook = $this->webhook($user);
        $profile = $this->profile($user, ['language' => 'pt'], ['nginx']);
        $matches = collect(['Nginx RCE', 'Nginx DoS'])->map(function (string $title) use ($profile) {
            $case = $this->newsCase($title, 'high', ['affected_products' => ['nginx']]);

            return AlertMatch::query()->create([
                'profile_id' => $profile->id, 'case_id' => $case->id, 'article_id' => $case->articles()->value('id'), 'kind' => 'new_case',
                'criticity' => 'high', 'score' => 80, 'reasons' => [['type' => 'stack', 'label' => 'nginx']], 'matched_at' => now(),
                'delivery_status' => 'held', 'delivery_note' => 'quiet_hours',
            ]);
        });

        app(Notifier::class)->grouped($webhook, $matches, 'quiet_hours');
        $grouped = $this->lastSent();

        $this->assertSame(['alert.grouped', 'quiet_hours', 2, '2 alertas novos'], [$grouped['event'], $grouped['data']['reason'], $grouped['data']['count'], $grouped['data']['title']]);
        $this->assertSame(['Nginx RCE', 'Nginx DoS'], array_column(array_column($grouped['data']['alerts'], 'case'), 'title'));
        $this->assertSame(['nginx'], $grouped['data']['alerts'][0]['terms']);

        app(Notifier::class)->digest($webhook, Carbon::parse('2026-11-03', 'America/Sao_Paulo'));
        $digest = $this->lastSent();

        $this->assertSame('alert.digest', $digest['event']);
        // O histórico (jsonb) não guarda a ordem das chaves: o receptor lê pelo nome.
        $this->assertEqualsCanonicalizing(['critical', 'high', 'medium', 'low'], array_keys($digest['data']['news']['counts']));
        $this->assertSame(2, $digest['data']['news']['total']);
        $this->assertCount(2, $digest['data']['news']['matched']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $digest['data']['schedule']['weekdays']);
        $this->assertSame('America/Sao_Paulo', $digest['data']['schedule']['timezone']);
        $this->assertSame([], $digest['data']['radar']);
    }

    public function test_the_test_and_the_tests_ready_notice(): void
    {
        $webhook = $this->webhook($this->client(), ['muted_types' => ['github']]);

        app(Notifier::class)->test($webhook);
        $test = $this->lastSent();

        $this->assertSame('test', $test['event']);
        $this->assertSame(['news', 'radar'], $test['data']['webhook']['types']);
        $this->assertTrue($test['data']['webhook']['signed']);
        $this->assertStringContainsString('Recebe: só notícias e Radar de domínios.', $test['data']['text']);

        // O aviso dos testes de segurança: só o domínio e o link, como nos outros apps.
        $notice = app(MessageBuilder::class)->testsReady(['cliente.com.br'], 'https://example.com/alertas/testes');
        $this->assertSame('tests.ready', $notice['event']);
        $this->assertSame(['title', 'text', 'domains', 'url'], array_keys($notice['data']));
        $this->assertSame(['cliente.com.br'], $notice['data']['domains']);
        $this->assertStringStartsWith('Os testes de segurança do Security Monitor foram ativados.', $notice['data']['text']);
    }

    public function test_radar_and_github_alerts_come_in_fields(): void
    {
        $user = $this->client();
        $webhook = $this->webhook($user);

        $watch = LookalikeWatch::query()->create([
            'user_id' => $user->id, 'domain_id' => $this->proven($user, 'acmepay.com.br')->id, 'company_name' => 'Acme', 'brands' => ['Acme Pay'],
            'step_reached' => 3, 'completed_at' => now(), 'status' => 'active', 'scan_status' => 'done',
        ]);
        $lookalike = $watch->lookalikes()->create([
            'host' => 'acmepay-login.com', 'techniques' => ['palavra'], 'ips' => ['45.90.20.10'], 'state' => 'content', 'risk' => 'critical', 'first_seen_at' => now(),
            'registered_at' => now()->subDays(3), 'mx' => ['mx.barato.example'], 'rdap' => ['registrar' => 'NameSilo, LLC'], 'hosting' => ['org' => 'Hostinger'],
            'signals' => [['key' => 'password', 'label' => 'Pede senha', 'points' => 20]],
        ]);
        $alert = LookalikeAlert::query()->create(['lookalike_id' => $lookalike->id, 'kind' => 'new', 'risk' => 'critical', 'status' => 'queued', 'ai_score' => 91, 'ai_reason' => 'Imita a tela de login.']);

        $radar = app(AlertMessages::class)->alert($alert, $webhook);
        $this->assertSame('radar.alert', $radar['event']);
        $this->assertSame(['critical', 'acmepay-login.com', 'acmepay.com.br', 'NameSilo, LLC', true], [$radar['data']['risk'], $radar['data']['domain'], $radar['data']['watched_domain'], $radar['data']['registrar'], $radar['data']['receives_email']]);
        $this->assertSame(['relevance' => 91, 'reason' => 'Imita a tela de login.'], $radar['data']['ai_review']);
        $this->assertSame(['view', 'kit', 'ours', 'safe', 'threat'], array_keys($radar['data']['links']));

        $monitor = GithubMonitor::query()->create([
            'user_id' => $user->id, 'status' => 'active', 'step_reached' => 6, 'completed_at' => now(), 'orgs' => ['acmepay'], 'private_repos' => [], 'public_repos' => [],
            'key_names' => ['ACME_SITE_BR'], 'token_prefixes' => [], 'hostnames' => [], 'email_domains' => [], 'brands' => ['Acme Pay'],
            'checks' => array_keys(GithubMonitor::CHECKS), 'min_level' => 'high', 'next_scan_at' => now(),
        ]);
        $finding = $monitor->findings()->create([
            'fingerprint' => sha1('x'), 'kind' => 'key_leak', 'terms' => ['ACME_SITE_BR'], 'repo' => 'dev-fulano/teste-api', 'repo_owner' => 'dev-fulano',
            'repo_owner_type' => 'User', 'path' => 'config/.env', 'html_url' => 'https://github.com/dev-fulano/teste-api/blob/abc/config/.env',
            'fragment' => 'ACME_SITE_BR=Zq8x••••••••', 'auto_level' => 'critical', 'first_seen_at' => now(), 'last_seen_at' => now(), 'status' => 'queued',
            'secrets' => [['name' => 'ACME_SITE_BR', 'source' => 'client_key', 'kind' => 'literal', 'length' => 24, 'entropy' => 4.6, 'looks_real' => true, 'masked' => 'Zq8x••••••••']],
            'signals' => [['key' => 'real', 'label' => 'Valor com cara de real em ACME_SITE_BR']],
        ]);

        $github = app(GithubMessages::class)->alert($finding, $webhook);
        $this->assertSame('github.finding', $github['event']);
        $this->assertSame(['critical', 'key_leak', 'dev-fulano/teste-api', 'config/.env', false], [$github['data']['level'], $github['data']['kind'], $github['data']['repository'], $github['data']['path'], $github['data']['own_repository']]);
        $this->assertSame([['name' => 'ACME_SITE_BR', 'masked' => 'Zq8x••••••••', 'length' => 24, 'looks_real' => true]], $github['data']['secrets']);
        $this->assertSame(['view', 'github', 'relevant', 'irrelevant'], array_keys($github['data']['links']));
    }

    public function test_the_documented_verification_code_accepts_only_genuine_deliveries(): void
    {
        $secret = WebhookSecret::generate();
        $body = WebhookClient::encode(WebhookClient::stamp(WebhookExample::payload(), now()));
        $genuine = ['Security-Monitor-Signature' => WebhookSecret::signature($secret, time(), $body), 'Security-Monitor-Notification' => $secret];
        $cases = [
            'genuína' => [$body, $genuine, 204],
            'corpo trocado' => [str_replace('critical', 'low', $body), $genuine, 401],
            'velha (repetição)' => [$body, ['Security-Monitor-Signature' => WebhookSecret::signature($secret, time() - 600, $body)] + $genuine, 401],
            'outro segredo' => [$body, ['Security-Monitor-Notification' => 'secmon_whsec_'.str_repeat('a', 43)] + $genuine, 401],
            'sem assinatura' => [$body, ['Security-Monitor-Notification' => $secret], 401],
        ];
        $ran = [];

        foreach (WebhookExample::verification() as $language => $code) {
            $runner = match (true) {
                str_starts_with($language, 'PHP') => WebhookSnippetRunner::php(...),
                str_starts_with($language, 'Python') => WebhookSnippetRunner::python(...),
                default => WebhookSnippetRunner::node(...),
            };

            foreach ($cases as $case => [$payload, $headers, $expected]) {
                $status = $runner($code, $secret, $payload, $headers);

                if ($status === null) {
                    continue 2; // sem o interpretador nesta máquina
                }

                $this->assertSame($expected, $status, "{$language}: {$case}");
            }

            $ran[] = $language;
        }

        // O de PHP roda sempre (a imagem dos testes tem PHP e Python; o Node, quando houver).
        $this->assertContains('PHP', $ran);
    }
}
