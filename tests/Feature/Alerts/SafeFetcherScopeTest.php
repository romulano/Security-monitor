<?php

namespace Tests\Feature\Alerts;

use App\Services\Alerts\Discovery\SafeFetcher;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O escopo opcional do SafeFetcher: com ele, cada salto (a URL pedida e cada redirect) só segue
 * para o host e o IP que o escopo aceita, e IP direto é recusado. Sem ele, vale o de sempre, que é
 * o que a descoberta, a monitoria e as imagens do admin usam.
 */
class SafeFetcherScopeTest extends TestCase
{
    private FakeDnsResolver $dns;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = (new FakeDnsResolver)
            ->set('acme.com.br', 'ips', ['34.1.1.1'])
            ->set('www.acme.com.br', 'ips', ['34.1.1.2'])
            ->set('terceiro.com', 'ips', ['34.9.9.9']);
    }

    private function fetcher(int $budget = 5, ?Closure $allow = null): SafeFetcher
    {
        $fetcher = new SafeFetcher($this->dns);
        $fetcher->startScan($budget, $allow);

        return $fetcher;
    }

    /** O domínio do cliente e os subdomínios dele, só nos IPs que eram dele no início da varredura. */
    private function scope(): Closure
    {
        return fn (string $host, string $ip, int $port) => ($host === 'acme.com.br' || str_ends_with($host, '.acme.com.br'))
            && in_array($ip, ['34.1.1.1', '34.1.1.2'], true);
    }

    private function log(): Closure
    {
        return function (string $message) {
            $this->lines[] = $message;
        };
    }

    public function test_without_a_scope_nothing_changes(): void
    {
        Http::fake([
            'https://acme.com.br/' => Http::response('', 302, ['Location' => 'https://terceiro.com/entrar']),
            'https://terceiro.com/entrar' => Http::response('<html>login</html>'),
            'http://34.5.5.5/' => Http::response('pelo IP'),
        ]);
        $fetcher = $this->fetcher();

        $page = $fetcher->get('https://acme.com.br/', $this->log());

        $this->assertTrue($page['ok']);
        $this->assertSame('https://terceiro.com/entrar', $page['url'], 'redirect para outro host público, como sempre');
        $this->assertTrue($fetcher->get('http://34.5.5.5/', $this->log())['ok'], 'IP direto público, como sempre');
        Http::assertSentCount(3);
    }

    public function test_with_a_scope_a_redirect_out_of_it_is_refused_before_any_request_to_it(): void
    {
        Http::fake([
            'https://acme.com.br/' => Http::response('', 302, ['Location' => 'https://terceiro.com/entrar']),
            '*' => Http::response('não devia ser chamado'),
        ]);

        $page = $this->fetcher(allow: $this->scope())->get('https://acme.com.br/', $this->log());

        $this->assertFalse($page['ok']);
        $this->assertTrue($page['blocked']);
        $this->assertSame('terceiro.com fora do escopo da varredura', $page['error']);
        $this->assertContains('terceiro.com → 34.9.9.9: fora do escopo desta varredura, nenhum request feito', $this->lines);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'terceiro.com'));
    }

    public function test_with_a_scope_a_redirect_inside_it_is_followed(): void
    {
        Http::fake([
            'https://acme.com.br/' => Http::response('', 301, ['Location' => 'https://www.acme.com.br/']),
            'https://www.acme.com.br/' => Http::response('<html>Acme</html>'),
        ]);

        $page = $this->fetcher(allow: $this->scope())->get('https://acme.com.br/', $this->log());

        $this->assertTrue($page['ok']);
        $this->assertSame('https://www.acme.com.br/', $page['url']);
        Http::assertSentCount(2);
    }

    public function test_with_a_scope_an_ip_literal_is_refused_even_from_a_redirect(): void
    {
        Http::fake([
            'https://acme.com.br/' => Http::response('', 302, ['Location' => 'http://34.1.1.1/admin']),
            '*' => Http::response('não devia ser chamado'),
        ]);
        $fetcher = $this->fetcher(allow: fn () => true);

        // Até o IP que é do cliente fica de fora: sem nome, não há o que conferir.
        foreach (['http://34.1.1.1/', 'https://[2606:4700::1111]/'] as $url) {
            $page = $fetcher->get($url, $this->log());
            $this->assertTrue($page['blocked'], $url);
            $this->assertSame('IP direto fora do escopo', $page['error'], $url);
        }

        $this->assertSame('IP direto fora do escopo', $fetcher->get('https://acme.com.br/', $this->log())['error']);
        $this->assertContains('recusado: http://34.1.1.1/admin (IP direto, fora do escopo desta varredura)', $this->lines);
        Http::assertSentCount(1);
    }

    public function test_the_scope_judges_the_ip_the_connection_is_pinned_to_and_the_port(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>')]);

        // O nome continua no escopo, mas o DNS passou a apontar para outro lugar.
        $this->dns->set('acme.com.br', 'ips', ['34.7.7.7']);
        $this->assertSame('acme.com.br fora do escopo da varredura', $this->fetcher(allow: $this->scope())->get('https://acme.com.br/', $this->log())['error']);
        Http::assertNothingSent();

        $onlyHttps = $this->fetcher(allow: fn (string $host, string $ip, int $port) => $port === 443);
        $this->assertTrue($onlyHttps->get('http://www.acme.com.br/', $this->log())['blocked']);
        $this->assertTrue($onlyHttps->get('https://www.acme.com.br/', $this->log())['ok']);
        Http::assertSentCount(1);
    }

    public function test_a_scope_never_widens_the_ip_guard(): void
    {
        Http::fake(['*' => Http::response('segredo')]);
        $this->dns->set('acme.com.br', 'ips', ['10.0.0.5']);

        $page = $this->fetcher(allow: fn () => true)->get('https://acme.com.br/', $this->log());

        $this->assertTrue($page['blocked']);
        $this->assertSame('acme.com.br aponta para 10.0.0.5 (rede privada)', $page['error']);
        Http::assertNothingSent();
    }

    public function test_a_new_scan_without_a_scope_drops_the_previous_one(): void
    {
        Http::fake(['https://terceiro.com/' => Http::response('<html>ok</html>')]);
        $fetcher = $this->fetcher(allow: $this->scope());
        $this->assertTrue($fetcher->get('https://terceiro.com/', $this->log())['blocked']);

        $fetcher->startScan(5);

        $this->assertTrue($fetcher->get('https://terceiro.com/', $this->log())['ok']);
        Http::assertSentCount(1);
    }
}
