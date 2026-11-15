<?php

namespace Tests\Feature\Security;

use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Security\TlsProbe;
use Tests\TestCase;

/** O handshake TLS: o que ele lê de um certificado e a recusa de endereço interno antes de abrir qualquer conexão. */
class TlsProbeTest extends TestCase
{
    use SecurityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSecurity();
    }

    /** Certificado autoassinado gerado na hora, para o nome e a validade pedidos. */
    private function certificate(string $name, int $days): \OpenSSLCertificate
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $name, 'organizationName' => 'Acme Ltda'], $key);

        return openssl_csr_sign($csr, null, $key, $days);
    }

    public function test_it_reads_validity_subject_issuer_and_fingerprint_without_keeping_the_certificate(): void
    {
        $cert = $this->certificate('acme.com.br', 30);

        $report = (new TlsProbe($this->dns))->describe($cert, 'TLSv1.3', trusted: true);

        $this->assertTrue($report->reachable);
        $this->assertTrue($report->trusted);
        $this->assertSame('TLSv1.3', $report->protocol);
        $this->assertSame('acme.com.br', $report->subject);
        $this->assertSame('acme.com.br', $report->issuer, 'autoassinado: emissor é o próprio titular');
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $report->notAfter->getTimestamp(), 120);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $report->fingerprint);
    }

    public function test_an_untrusted_certificate_keeps_the_reason(): void
    {
        $report = (new TlsProbe($this->dns))->describe($this->certificate('acme.com.br', 10), 'TLSv1.2', trusted: false, error: 'certificado autoassinado');

        $this->assertFalse($report->trusted);
        $this->assertSame('certificado autoassinado', $report->error);
    }

    public function test_no_certificate_means_no_report(): void
    {
        $this->assertNull((new TlsProbe($this->dns))->describe(null, null, false));
        $this->assertNull((new TlsProbe($this->dns))->describe('isto não é um certificado', null, false));
    }

    public function test_a_host_that_does_not_resolve_is_unreachable_and_opens_no_connection(): void
    {
        $report = (new TlsProbe($this->dns))->inspect('naoexiste.com.br');

        $this->assertFalse($report->reachable);
        $this->assertStringContainsString('não resolve no DNS', $report->error);
    }

    public function test_a_host_that_points_inside_is_blocked_before_any_connection(): void
    {
        foreach (['127.0.0.1' => 'loopback', '10.0.0.5' => 'rede privada', '169.254.169.254' => 'metadata', '172.16.9.3' => 'rede privada', '::1' => 'loopback'] as $ip => $reason) {
            $this->dns->set('interno.com.br', 'ips', [$ip]);

            $report = (new TlsProbe($this->dns))->inspect('interno.com.br');

            $this->assertNotNull($report->blocked, $ip);
            $this->assertFalse($report->reachable);
            $this->assertStringContainsString($ip, $report->error);
            $this->assertStringContainsString($reason, $report->error, $ip);
        }
    }

    public function test_one_private_address_among_public_ones_blocks_the_whole_host(): void
    {
        // Rebinding por DNS de resposta múltipla: basta um endereço interno.
        $this->dns->set('misto.com.br', 'ips', ['104.21.1.1', '10.0.0.7']);

        $this->assertNotNull((new TlsProbe($this->dns))->inspect('misto.com.br')->blocked);
    }

    public function test_the_probe_resolves_names_through_the_dns_resolver_of_the_container(): void
    {
        $this->assertSame($this->dns, app(DnsResolver::class));
    }
}
