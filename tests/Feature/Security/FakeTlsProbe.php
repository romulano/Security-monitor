<?php

namespace Tests\Feature\Security;

use App\Services\Security\TlsProbe;
use App\Services\Security\TlsReport;

/** Handshake de mentira: devolve o relatório combinado e guarda quais hosts foram consultados. */
class FakeTlsProbe extends TlsProbe
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(public TlsReport $report) {}

    public function inspect(string $host): TlsReport
    {
        $this->asked[] = $host;

        return $this->report;
    }
}
