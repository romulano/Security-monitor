<?php

namespace Tests\Feature\Security;

use App\Services\Alerts\Discovery\SafeFetcher;
use App\Services\Security\CheckResult;
use App\Services\Security\SiteProbe;
use App\Services\Security\SiteScanner;
use App\Services\Security\TlsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O servidor faz as requisições a pedido do admin: SSRF, DNS rebinding, redirecionamento para dentro,
 * resposta gigante, carga. O monitor só pode ler (GET), só em porta 80/443, só em IP público.
 */
class SecurityAttackTest extends TestCase
{
    use RefreshDatabase, SecurityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
        $this->goodDns();
    }

    /** @return list<string> */
    private function urlsAsked(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
    }

    public function test_a_redirect_to_another_port_is_refused(): void
    {
        $this->fakeTls();
        $this->fakeHttp([
            'http://acme.com.br/' => Http::response('', 301, ['Location' => 'https://acme.com.br/']),
            'https://acme.com.br/' => Http::response('', 302, ['Location' => 'http://acme.com.br:9200/_cat/indices']),
            '*' => Http::response('não existe', 404),
        ]);

        app(SiteScanner::class)->scan($this->site());

        foreach ($this->urlsAsked() as $url) {
            $this->assertStringNotContainsString(':9200', $url);
        }
    }

    public function test_an_endless_redirect_chain_stops_after_three_hops(): void
    {
        $this->fakeTls();
        $this->fakeHttp([
            'http://acme.com.br/' => Http::response('', 301, ['Location' => 'https://acme.com.br/']),
            'https://acme.com.br/' => Http::response('', 302, ['Location' => 'https://acme.com.br/a']),
            'https://acme.com.br/a' => Http::response('', 302, ['Location' => 'https://acme.com.br/b']),
            'https://acme.com.br/b' => Http::response('', 302, ['Location' => 'https://acme.com.br/c']),
            'https://acme.com.br/c' => Http::response('', 302, ['Location' => 'https://acme.com.br/d']),
            'https://acme.com.br/d' => Http::response('chegou longe demais', 200),
            '*' => Http::response('não existe', 404),
        ]);

        app(SiteScanner::class)->scan($this->site());

        $this->assertNotContains('https://acme.com.br/d', $this->urlsAsked());
    }

    public function test_the_monitor_only_ever_reads(): void
    {
        $this->fakeTls();
        $this->fakeWeb();

        app(SiteScanner::class)->scan($this->site());

        $this->assertNotEmpty($this->urlsAsked());
        Http::recorded()->each(fn ($pair) => $this->assertSame('GET', $pair[0]->method(), $pair[0]->url()));
    }

    public function test_it_only_speaks_ports_80_and_443_and_identifies_itself(): void
    {
        $this->fakeTls();
        $this->fakeWeb();

        app(SiteScanner::class)->scan($this->site());

        foreach ($this->urlsAsked() as $url) {
            $this->assertNull(parse_url($url, PHP_URL_PORT), $url);
            $this->assertContains(parse_url($url, PHP_URL_SCHEME), ['http', 'https']);
        }

        Http::assertSent(fn (Request $r) => str_contains($r->header('User-Agent')[0] ?? '', 'SecurityMonitorBot'));
    }

    public function test_a_scan_stays_inside_the_request_budget_even_with_every_check_running(): void
    {
        $this->fakeTls();
        $this->fakeWeb();

        app(SiteScanner::class)->scan($this->site());

        $this->assertLessThanOrEqual((int) config('secmon.security.request_budget'), count($this->urlsAsked()));
    }

    public function test_a_gigantic_home_page_is_cut_at_the_configured_limit(): void
    {
        $this->fakeTls();
        $this->fakeHttp(['https://acme.com.br/' => Http::response(str_repeat('a', 5 * 1024 * 1024), 200, $this->goodHeaders()), '*' => Http::response('x', 404)]);
        $site = $this->site();

        $probe = new SiteProbe($site, app(SafeFetcher::class), app(TlsProbe::class));
        $home = $probe->home();

        $this->assertLessThanOrEqual((int) config('secmon.security.home_bytes'), $home['bytes']);
        $this->assertTrue($home['truncated']);
    }

    public function test_a_gigantic_sensitive_file_is_read_only_up_to_a_few_kilobytes(): void
    {
        $this->fakeTls();
        $this->fakeWeb(paths: ['/.env' => Http::response("APP_KEY=base64:x\n".str_repeat("LIXO=1\n", 400000))]);

        $probe = new SiteProbe($this->site(), app(SafeFetcher::class), app(TlsProbe::class));
        $response = $probe->fetch('/.env', (int) config('secmon.security.probe_bytes'));

        $this->assertLessThanOrEqual(4096, $response['bytes']);
        $this->assertTrue($response['truncated']);
    }

    public function test_the_signature_at_the_start_of_a_huge_file_is_enough_to_flag_it(): void
    {
        $this->fakeTls();
        $this->fakeWeb(paths: ['/.env' => Http::response("APP_KEY=base64:x\n".str_repeat("LIXO=1\n", 400000))]);

        $result = collect(app(SiteScanner::class)->scan($this->site())->results)->first(fn (CheckResult $r) => $r->id === 'file.exposed');

        $this->assertSame('critico', $result->status);
        $this->assertSame('/.env', $result->subject);
    }

    public function test_every_scan_can_only_hit_hosts_of_the_site_it_was_asked_for(): void
    {
        $this->fakeTls();
        $this->fakeWeb();

        app(SiteScanner::class)->scan($this->site());

        foreach ($this->urlsAsked() as $url) {
            $this->assertSame('acme.com.br', parse_url($url, PHP_URL_HOST), $url);
        }
    }

    public function test_the_certificate_probe_is_asked_about_the_registered_host_and_nothing_else(): void
    {
        $tls = $this->fakeTls();
        $this->fakeWeb();

        app(SiteScanner::class)->scan($this->site());

        $this->assertSame(['acme.com.br'], array_values(array_unique($tls->asked)));
    }
}
