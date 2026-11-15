<?php

namespace App\Logging;

use DateTimeZone;
use Illuminate\Log\Logger;

/**
 * Carimba os logs no horário de Brasília (app.display_timezone). O app
 * continua em UTC, porque o banco grava as datas em UTC.
 */
class DisplayTimezone
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->setTimezone(new DateTimeZone((string) config('app.display_timezone')));
    }
}
