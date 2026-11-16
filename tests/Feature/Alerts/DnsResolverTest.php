<?php

namespace Tests\Feature\Alerts;

use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\NativeDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O resolver de DNS (DnsResolver), por fora de qualquer prova de posse: o PTR por IP, com "não sei"
 * (nulo) separado de "sem registro" (lista vazia), e o nome reverso de um IP.
 */
class DnsResolverTest extends TestCase
{
    use RefreshDatabase;

    private function dns(): FakeDnsResolver
    {
        $this->app->instance(DnsResolver::class, $dns = new FakeDnsResolver);

        return $dns;
    }

    public function test_without_a_fake_the_dns_of_the_tests_does_not_answer(): void
    {
        // O TestCase liga um DNS que não responde por padrão (FailingDnsResolver).
        $this->assertNull(app(DnsResolver::class)->ptrOrNull('203.0.113.10'));
    }

    public function test_the_fake_dns_answers_ptr_by_ip(): void
    {
        $dns = $this->dns()->set('203.0.113.10', 'ptr', ['www.empresa.com.br']);

        $this->assertSame(['www.empresa.com.br'], $dns->ptrOrNull('203.0.113.10'));
        $this->assertSame([], $dns->ptrOrNull('203.0.113.11'));

        $dns->failing['203.0.113.10'] = 1;
        $this->assertNull($dns->ptrOrNull('203.0.113.10'));
    }

    public function test_the_reverse_name_of_an_ip(): void
    {
        $this->assertSame('10.113.0.203.in-addr.arpa', NativeDnsResolver::reverseName('203.0.113.10'));
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            NativeDnsResolver::reverseName('2001:db8::1'),
        );
        $this->assertNull(NativeDnsResolver::reverseName('empresa.com.br'));
        $this->assertSame([], (new NativeDnsResolver)->ptrOrNull('não é ip'));
    }
}
