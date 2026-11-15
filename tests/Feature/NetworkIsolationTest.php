<?php

namespace Tests\Feature;

use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\SafeFetcher;
use App\Services\Security\TlsProbe;
use Illuminate\Support\Facades\Http;
use Tests\Support\FailingDnsResolver;
use Tests\Support\FailingTlsProbe;
use Tests\TestCase;

/**
 * O padrão de todo teste (TestCase): nada sai para a rede, nem pelo HTTP, nem pelo DNS, nem por
 * um handshake TLS. Medido na suíte inteira numa rede Docker sem internet: nenhum teste dependia
 * do DNS de verdade, então trocar o padrão não quebrou nenhum.
 */
class NetworkIsolationTest extends TestCase
{
    public function test_dns_and_tls_answer_nothing_unless_the_test_brings_its_own(): void
    {
        $dns = app(DnsResolver::class);
        $this->assertInstanceOf(FailingDnsResolver::class, $dns);
        $this->assertNull($dns->ipsOrNull('acme.com.br'), 'DNS que não respondeu: "não sei"');
        $this->assertSame([], $dns->txt('acme.com.br'));

        $tls = app(TlsProbe::class);
        $this->assertInstanceOf(FailingTlsProbe::class, $tls);
        $this->assertFalse($tls->inspect('acme.com.br')->reachable);
    }

    public function test_code_that_reads_a_site_gets_no_answer_and_sends_nothing(): void
    {
        Http::fake();
        $fetcher = app(SafeFetcher::class);
        $fetcher->startScan(5);

        $page = $fetcher->get('https://acme.com.br/', fn () => null);

        $this->assertFalse($page['ok']);
        $this->assertSame('acme.com.br não resolve no DNS', $page['error']);
        $this->assertContains('ips acme.com.br', app(DnsResolver::class)->asked);
        Http::assertNothingSent();
    }
}
