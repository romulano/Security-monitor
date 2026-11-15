<?php

namespace Tests;

use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Security\TlsProbe;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FailingDnsResolver;
use Tests\Support\FailingTlsProbe;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum teste fala com a internet: URL sem Http::fake falha na hora, em vez de depender
        // da rede (um teste do GitHub chamava hooks.slack.com de verdade e oscilava).
        Http::preventStrayRequests();

        // Nem pelo DNS nem por um handshake TLS, que não passam pelo Http: o padrão é "não respondeu".
        // Teste que esquecesse o falso cairia no DNS de verdade (dns_get_record) sem ninguém ver.
        // Quem precisa de DNS ou de certificado troca pelo falso dele (FakeDnsResolver, FakeTlsProbe).
        $this->app->instance(DnsResolver::class, new FailingDnsResolver);
        $this->app->instance(TlsProbe::class, new FailingTlsProbe);
    }
}
