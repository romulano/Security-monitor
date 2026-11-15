<?php

namespace Tests\Support;

use App\Services\Security\TlsProbe;
use App\Services\Security\TlsReport;

/**
 * O handshake TLS de todo teste que não pede outro: nenhum socket abre, e o site "não
 * responde". Teste que precisa de certificado liga o FakeTlsProbe, que entra por cima deste.
 */
class FailingTlsProbe extends TlsProbe
{
    /** @var list<string> hosts consultados, para o teste conferir que nada saiu */
    public array $asked = [];

    public function __construct() {}

    public function inspect(string $host): TlsReport
    {
        $this->asked[] = $host;

        return TlsReport::unreachable('teste sem rede: nenhum handshake TLS de verdade');
    }
}
