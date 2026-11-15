<?php

namespace Tests\Feature\Github;

use App\Models\GithubFinding;
use App\Models\GithubMonitor;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Alerts\AlertsTestHelpers;

/** Monitoramento ativo, achados prontos e as respostas falsas do GitHub e do hermes. */
trait GithubTestHelpers
{
    use AlertsTestHelpers;

    /** Um valor de chave com cara de real: nunca pode aparecer no banco, na tela, no prompt nem na mensagem. */
    protected const SECRET = 'Zq8xR2mLp0Vb7Kd9TwYc4Nf1';

    protected function monitor(?User $user = null, array $attributes = []): GithubMonitor
    {
        return GithubMonitor::query()->create($attributes + [
            'user_id' => ($user ?? $this->client())->id,
            'status' => 'active',
            'step_reached' => 6,
            'completed_at' => now(),
            'orgs' => ['acmepay'],
            'private_repos' => ['acmepay/pagamentos-core'],
            'public_repos' => ['acmepay/sdk-js'],
            'key_names' => ['ACME_SITE_BR'],
            'token_prefixes' => ['acmk_live_'],
            'hostnames' => ['jenkins.interno.acmepay.com.br'],
            'email_domains' => ['acmepay.com.br'],
            'brands' => ['Acme Pay'],
            'checks' => array_keys(GithubMonitor::CHECKS),
            'min_level' => 'high',
            'next_scan_at' => now(),
        ]);
    }

    protected function finding(GithubMonitor $monitor, array $attributes = []): GithubFinding
    {
        return $monitor->findings()->create($attributes + [
            'fingerprint' => sha1(uniqid('', true)),
            'kind' => 'key_leak',
            'terms' => ['ACME_SITE_BR'],
            'repo' => 'dev-fulano/teste-api',
            'repo_owner' => 'dev-fulano',
            'repo_owner_type' => 'User',
            'path' => 'config/.env',
            'html_url' => 'https://github.com/dev-fulano/teste-api/blob/abc/config/.env',
            'fragment' => 'ACME_SITE_BR=Zq8x••••••••',
            'secrets' => [['name' => 'ACME_SITE_BR', 'source' => 'client_key', 'kind' => 'literal', 'length' => 24, 'entropy' => 4.6, 'looks_real' => true, 'masked' => 'Zq8x••••••••']],
            'signals' => [['key' => 'real', 'label' => 'Valor com cara de real em ACME_SITE_BR']],
            'auto_level' => 'critical',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'status' => 'triage',
        ]);
    }

    /** Hermes respondendo em sequência (uma resposta por chamada), e Slack aceitando. */
    protected function hermes(array ...$replies): void
    {
        Settings::setMany(['enricher.hermes_url' => 'http://hermes.test']);
        $sequence = Http::sequence();

        foreach ($replies as $reply) {
            $sequence->push(['choices' => [['message' => ['content' => json_encode($reply)]]]]);
        }

        Http::fake([
            'hermes.test/*' => $sequence,
            'hooks.slack.com/*' => Http::response('ok'),
        ]);
    }

    /** As requisições feitas ao hermes, na ordem. */
    protected function hermesCalls(): array
    {
        return Http::recorded(fn ($request) => str_starts_with($request->url(), 'http://hermes.test/'))->map(fn ($pair) => $pair[0])->values()->all();
    }
}
