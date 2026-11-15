<?php

namespace Tests\Feature\Github;

use App\Jobs\ScanGithub;
use App\Jobs\TriageGithubFinding;
use App\Services\Github\GithubScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Varredura diária: API oficial do GitHub, termo exato conferido, segredo mascarado, um achado por arquivo ou repositório. */
class GithubScanTest extends TestCase
{
    use GithubTestHelpers, RefreshDatabase;

    /** @var list<string> repositórios públicos da organização do cliente, como a API devolve */
    private array $orgRepos = ['acmepay/sdk-js', 'acmepay/site'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Sleep::fake();
        config(['secmon.github.token' => 'ghp_test', 'secmon.github.api_url' => 'https://api.github.com']);
    }

    private function repo(string $fullName, array $extra = []): array
    {
        [$owner, $name] = explode('/', $fullName);

        return $extra + [
            'full_name' => $fullName, 'name' => $name, 'html_url' => 'https://github.com/'.$fullName,
            'description' => null, 'fork' => false, 'private' => false, 'stargazers_count' => 0,
            'created_at' => now()->subYear()->toIso8601String(), 'pushed_at' => now()->subMonth()->toIso8601String(),
            'owner' => ['login' => $owner, 'type' => $owner === 'acmepay' ? 'Organization' : 'User'],
        ];
    }

    private function fakeGithub(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $q = $query['q'] ?? '';
            $limits = ['x-ratelimit-remaining' => '9', 'x-ratelimit-reset' => (string) (time() + 60)];

            if (str_contains($url, '/search/code')) {
                $items = $q === '"ACME_SITE_BR"' ? [
                    [
                        'name' => '.env', 'path' => 'config/.env', 'html_url' => 'https://github.com/dev-fulano/teste-api/blob/abc/config/.env',
                        'repository' => $this->repo('dev-fulano/teste-api', ['description' => 'Testes da API da Acme']),
                        'text_matches' => [['fragment' => "APP_ENV=production\nACME_SITE_BR=".self::SECRET."\nDB_HOST=localhost"]],
                    ],
                    [
                        // A busca ignora pontuação: "acme site br" não é o nome da chave.
                        'name' => 'README.md', 'path' => 'README.md', 'html_url' => 'https://github.com/outro/blog/blob/abc/README.md',
                        'repository' => $this->repo('outro/blog'),
                        'text_matches' => [['fragment' => 'acme site br is a nice phrase']],
                    ],
                ] : [];

                return Http::response(['total_count' => count($items), 'items' => $items], 200, $limits);
            }

            if (str_contains($url, '/search/repositories')) {
                $items = match ($q) {
                    'acmepay in:name,description' => [
                        $this->repo('golpista/acmepay-login-clone', ['description' => 'Phishing kit Acme Pay login', 'created_at' => now()->subDays(3)->toIso8601String()]),
                        $this->repo('acmepay/sdk-js', ['description' => 'SDK oficial da Acme Pay']),
                    ],
                    'pagamentos-core in:name' => [$this->repo('ex-func/pagamentos-core'), $this->repo('ex-func/pagamentos-core-v2')],
                    default => [],
                };

                return Http::response(['total_count' => count($items), 'items' => $items], 200, $limits);
            }

            if (str_contains($url, '/users/acmepay/repos')) {
                return Http::response(array_map(fn ($name) => $this->repo($name), $this->orgRepos), 200, $limits);
            }

            // /repos/acmepay/pagamentos-core: privado continua 404.
            return Http::response(['message' => 'Not Found'], 404, $limits);
        });
    }

    public function test_scan_finds_the_leaked_key_masks_it_and_sends_new_findings_to_the_classifier(): void
    {
        $this->fakeGithub();
        $monitor = $this->monitor();

        $stats = app(GithubScanner::class)->scan($monitor);

        $this->assertSame(3, $stats['new']);
        $findings = $monitor->findings()->get()->keyBy('kind');
        $this->assertEqualsCanonicalizing(['key_leak', 'brand_repo', 'private_repo'], $findings->keys()->all());

        $leak = $findings['key_leak'];
        $this->assertSame('dev-fulano/teste-api', $leak->repo);
        $this->assertSame('config/.env', $leak->path);
        $this->assertStringContainsString('ACME_SITE_BR=Zq8x••••••••', $leak->fragment);
        $this->assertTrue($leak->secrets[0]['looks_real']);
        $this->assertSame('critical', $leak->auto_level);
        $this->assertSame('triage', $leak->status);

        $this->assertSame('high', $findings['brand_repo']->auto_level, 'kit de phishing com a marca');
        $this->assertSame('golpista/acmepay-login-clone', $findings['brand_repo']->repo);
        $this->assertSame('ex-func/pagamentos-core', $findings['private_repo']->repo, 'só o nome exato do repositório privado');

        // O valor da chave não existe em lugar nenhum do banco.
        $this->assertStringNotContainsString(self::SECRET, json_encode(DB::table('github_findings')->get()));
        $this->assertStringNotContainsString(self::SECRET, json_encode(DB::table('github_monitors')->get()));

        Queue::assertPushed(TriageGithubFinding::class, 3);
        $this->assertSame(['acmepay' => ['acmepay/sdk-js', 'acmepay/site']], $monitor->refresh()->known_repos, 'primeira varredura só guarda a lista da organização');
        $this->assertSame('done', $monitor->scan_status);
        $this->assertTrue($monitor->next_scan_at->isAfter(now()->addHours(23)));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/search/code') && $r->hasHeader('Authorization', 'Bearer ghp_test')
            && str_contains($r->header('Accept')[0], 'text-match'));
    }

    public function test_second_scan_finds_the_repository_that_became_public_and_does_not_repeat_old_findings(): void
    {
        $this->fakeGithub();
        $monitor = $this->monitor();
        app(GithubScanner::class)->scan($monitor);

        $this->orgRepos[] = 'acmepay/infra-segredos';
        $this->fakeGithub();
        $stats = app(GithubScanner::class)->scan($monitor->refresh());

        $this->assertSame(1, $stats['new']);
        $new = $monitor->findings()->where('kind', 'org_new_repo')->sole();
        $this->assertSame('acmepay/infra-segredos', $new->repo);
        $this->assertSame('medium', $new->auto_level);
        Queue::assertPushed(TriageGithubFinding::class, 4);
    }

    public function test_without_token_only_repository_searches_run(): void
    {
        config(['secmon.github.token' => null]);
        $this->fakeGithub();
        $monitor = $this->monitor();

        $stats = app(GithubScanner::class)->scan($monitor);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/search/code'));
        $this->assertContains('busca em código: sem token do GitHub', $stats['skipped']);
        $this->assertSame(0, $monitor->findings()->where('kind', 'key_leak')->count());
        $this->assertSame(1, $monitor->findings()->where('kind', 'brand_repo')->count());
    }

    public function test_rate_limit_stops_the_scan_and_tries_again_in_an_hour(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
            'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) (time() + 900),
        ])]);
        $monitor = $this->monitor();

        $stats = app(GithubScanner::class)->scan($monitor);

        $this->assertSame(0, $stats['queries']);
        $monitor->refresh();
        $this->assertStringContainsString('limite da API do GitHub', $monitor->last_error);
        $this->assertSame('done', $monitor->scan_status);
        $this->assertTrue($monitor->next_scan_at->between(now()->addMinutes(50), now()->addMinutes(70)));
        Http::assertSentCount(1);
    }

    public function test_finding_that_left_github_is_marked_as_removed(): void
    {
        $this->fakeGithub();
        $monitor = $this->monitor(attributes: ['key_names' => []]);
        $gone = $this->finding($monitor, ['status' => 'sent', 'last_seen_at' => now()->subDay(), 'repo' => 'dev-fulano/apagado', 'path' => 'x/.env']);

        app(GithubScanner::class)->scan($monitor);

        $this->assertNotNull($gone->refresh()->gone_at);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/repos/dev-fulano/apagado/contents/x/.env'));
    }

    public function test_scheduled_command_queues_who_is_due(): void
    {
        $due = $this->monitor();
        $this->monitor(attributes: ['next_scan_at' => now()->addHours(5)]);
        $this->monitor(attributes: ['status' => 'paused']);

        $this->artisan('secmon:github-scan --due')->expectsOutput('1 varredura(s) do GitHub na fila.')->assertSuccessful();

        Queue::assertPushed(ScanGithub::class, fn ($job) => $job->monitorId === $due->id);
        Queue::assertPushed(ScanGithub::class, 1);
    }
}
