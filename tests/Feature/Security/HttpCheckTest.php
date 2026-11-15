<?php

namespace Tests\Feature\Security;

use App\Services\Security\CheckResult;
use App\Services\Security\SiteScanner;
use App\Services\Security\TlsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** HTTPS, cabeçalhos, vazamento de versão, cookies e conteúdo misto: o que a página inicial e a porta 80 dizem. */
class HttpCheckTest extends TestCase
{
    use RefreshDatabase, SecurityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
        $this->goodDns();
        $this->fakeTls();
    }

    /** @return array<string, CheckResult> id|subject => resultado, da varredura completa */
    private function scan(): array
    {
        $report = app(SiteScanner::class)->scan($this->site());

        return collect($report->results)->keyBy(fn (CheckResult $r) => $r->key())->all();
    }

    public function test_a_well_configured_site_passes_every_http_check(): void
    {
        $this->fakeWeb();

        $r = $this->scan();

        foreach (['http.redirect', 'http.hsts', 'hdr.csp', 'hdr.frame', 'hdr.nosniff', 'hdr.referrer', 'hdr.permissions', 'hdr.coop', 'leak.server', 'leak.powered', 'mixed.content'] as $id) {
            $this->assertSame('ok', $r[$id.'|']->status, $id.': '.$r[$id.'|']->value);
        }
        $this->assertSame('redireciona (301) para HTTPS', $r['http.redirect|']->value);
        $this->assertSame('nenhum cookie na página inicial', $r['cookie.flags|']->value);
    }

    public function test_port_80_answering_without_redirecting_is_critical(): void
    {
        $this->fakeWeb();
        $this->fakeHttp(['http://acme.com.br/' => Http::response('<html>site em http</html>', 200), 'https://acme.com.br/*' => Http::response('x', 200, $this->goodHeaders())]);

        $this->assertSame('critico', $this->scan()['http.redirect|']->status);
    }

    public function test_a_redirect_that_stays_on_http_is_critical_and_a_closed_port_80_is_fine(): void
    {
        $this->fakeWeb();
        $this->fakeHttp(['http://acme.com.br/' => Http::response('', 302, ['Location' => '/entrar']), 'https://acme.com.br/*' => Http::response('x', 200, $this->goodHeaders())]);
        $stay = $this->scan()['http.redirect|'];
        $this->assertSame('critico', $stay->status);
        $this->assertStringContainsString('sem sair do HTTP', $stay->value);

        $this->fakeHttp(['http://acme.com.br/' => fn () => throw new ConnectionException('recusada'), 'https://acme.com.br/*' => Http::response('x', 200, $this->goodHeaders())]);
        $this->assertSame(['ok', 'porta 80 fechada'], [$this->scan()['http.redirect|']->status, $this->scan()['http.redirect|']->value]);
    }

    public function test_missing_or_short_hsts_is_a_warning(): void
    {
        $this->fakeWeb(headers: $this->goodHeaders(['Strict-Transport-Security' => 'max-age=300']));
        $this->assertSame('aviso', $this->scan()['http.hsts|']->status);
        $this->assertStringContainsString('max-age curto', $this->scan()['http.hsts|']->value);

        $headers = $this->goodHeaders();
        unset($headers['Strict-Transport-Security']);
        $this->fakeWeb(headers: $headers);
        $this->assertSame('ausente', $this->scan()['http.hsts|']->value);
    }

    public function test_the_security_headers_are_warnings_when_absent(): void
    {
        $this->fakeWeb(headers: ['Server' => 'cloudflare']);

        $r = $this->scan();

        foreach (['hdr.csp', 'hdr.frame', 'hdr.nosniff', 'hdr.referrer', 'hdr.permissions', 'hdr.coop', 'http.hsts'] as $id) {
            $this->assertSame('aviso', $r[$id.'|']->status, $id);
        }
    }

    public function test_csp_in_report_only_or_allowing_inline_script_is_a_warning(): void
    {
        $headers = $this->goodHeaders(['Content-Security-Policy-Report-Only' => "default-src 'self'"]);
        unset($headers['Content-Security-Policy']);
        $this->fakeWeb(headers: $headers);
        $this->assertStringContainsString('Report-Only', $this->scan()['hdr.csp|']->value);

        $this->fakeWeb(headers: $this->goodHeaders(['Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'"]));
        $this->assertSame('aviso', $this->scan()['hdr.csp|']->status);

        $this->fakeWeb(headers: $this->goodHeaders(['Content-Security-Policy' => "script-src 'self' 'unsafe-inline' 'nonce-abc123'"]));
        $this->assertSame('ok', $this->scan()['hdr.csp|']->status, 'com nonce, o inline é controlado');
    }

    public function test_frame_ancestors_in_the_csp_replaces_x_frame_options(): void
    {
        $headers = $this->goodHeaders(['Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'"]);
        unset($headers['X-Frame-Options']);
        $this->fakeWeb(headers: $headers);

        $this->assertSame('ok', $this->scan()['hdr.frame|']->status);
    }

    public function test_the_server_version_and_x_powered_by_leak_the_stack(): void
    {
        $this->fakeWeb(headers: $this->goodHeaders(['Server' => 'nginx/1.24.0', 'X-Powered-By' => 'PHP/8.4.27']));

        $r = $this->scan();

        $this->assertSame('aviso', $r['leak.server|']->status);
        $this->assertSame('Server: nginx/1.24.0', $r['leak.server|']->value);
        $this->assertSame('aviso', $r['leak.powered|']->status);
    }

    public function test_cookies_are_judged_by_name_and_flags_and_the_value_is_never_kept(): void
    {
        $this->fakeWeb(headers: $this->goodHeaders(['Set-Cookie' => [
            'laravel_session=SEGREDO-DA-SESSAO; path=/; Secure; HttpOnly; SameSite=Lax',
            'sessionid=OUTRO-SEGREDO; path=/',
            '_ga=GA1.2.3; path=/; Secure',
        ]]));

        $r = $this->scan();

        $this->assertSame('ok', $r['cookie.flags|laravel_session']->status);
        $this->assertSame('aviso', $r['cookie.flags|sessionid']->status);
        $this->assertSame('falta Secure, HttpOnly, SameSite', $r['cookie.flags|sessionid']->value);
        $this->assertSame('ok', $r['cookie.flags|_ga']->status, 'cookie de analytics não precisa de HttpOnly');

        $stored = json_encode(array_map(fn ($x) => [$x->value, $x->subject], $r));
        $this->assertStringNotContainsString('SEGREDO', $stored);
    }

    public function test_the_csrf_cookie_is_readable_by_javascript_on_purpose(): void
    {
        $this->fakeWeb(headers: $this->goodHeaders(['Set-Cookie' => ['XSRF-TOKEN=abc; path=/; Secure; SameSite=Lax', 'csrftoken=def; path=/']]));

        $r = $this->scan();

        $this->assertSame('ok', $r['cookie.flags|XSRF-TOKEN']->status, 'sem HttpOnly de propósito: o front lê o cookie');
        $this->assertSame('aviso', $r['cookie.flags|csrftoken']->status);
        $this->assertSame('falta Secure', $r['cookie.flags|csrftoken']->value, 'Secure continua valendo; HttpOnly e SameSite não');
    }

    public function test_mixed_content_is_counted_and_never_quoted(): void
    {
        $this->fakeWeb(body: '<html><img src="http://cdn.exemplo.com/a.png"><script src="http://x.exemplo.com/a.js"></script><a href="http://link.com">link comum</a></html>');

        $result = $this->scan()['mixed.content|'];

        $this->assertSame('aviso', $result->status);
        $this->assertSame('2 recurso(s) carregados por http://', $result->value);
    }

    public function test_a_site_that_does_not_answer_makes_the_header_checks_untestable_and_the_scan_an_error(): void
    {
        $this->dns->set('acme.com.br', 'ips', ['104.21.1.1']);
        $this->fakeHttp(['*' => fn () => throw new ConnectionException('sem rota')]);
        $this->fakeTls(TlsReport::unreachable('sem resposta TLS na porta 443'));

        $report = app(SiteScanner::class)->scan($this->site());

        $this->assertSame('erro', $report->status);
        $by = collect($report->results)->keyBy(fn ($r) => $r->key());
        $this->assertSame('nao_testavel', $by['http.hsts|']->status);
        $this->assertSame('nao_testavel', $by['hdr.csp|']->status);
    }

    public function test_a_home_page_that_never_reaches_https_leaves_the_header_checks_untestable(): void
    {
        $this->dns->set('acme.com.br', 'ips', ['104.21.1.1']);
        $this->fakeHttp([
            'http://acme.com.br/' => Http::response('', 301, ['Location' => 'http://acme.com.br/home']),
            'https://acme.com.br/' => Http::response('', 302, ['Location' => 'http://acme.com.br/inseguro']),
            'http://acme.com.br/inseguro' => Http::response('<html>http puro</html>', 200),
            '*' => Http::response('não existe', 404),
        ]);

        $by = collect(app(SiteScanner::class)->scan($this->site())->results)->keyBy(fn ($r) => $r->key());

        $this->assertSame('nao_testavel', $by['http.hsts|']->status);
        $this->assertStringContainsString('não termina em HTTPS', $by['http.hsts|']->value);
    }
}
