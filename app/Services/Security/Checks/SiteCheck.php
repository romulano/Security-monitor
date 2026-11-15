<?php

namespace App\Services\Security\Checks;

use App\Services\Security\CheckResult;
use App\Services\Security\SiteProbe;

/** Uma família de verificações de um site. Só lê: GET, DNS e handshake TLS. */
interface SiteCheck
{
    /** @return list<string> os ids do catálogo que ela produz (se ela falhar, cada um vira "não testável") */
    public function ids(): array;

    /** @return list<CheckResult> */
    public function run(SiteProbe $probe): array;
}
