<?php

namespace App\Services\Lookalike;

use App\Models\LookalikeAlert;
use App\Models\LookalikeCapture;
use App\Models\LookalikeWatch;

/**
 * Os prints do Radar no disco: das visitas, da referência do site do cliente
 * e a foto que foi no aviso. Sempre dentro de shots/ da pasta do navegador;
 * nulo quando não há o arquivo (ou o caminho gravado aponta para fora dela).
 */
class LookalikeShots
{
    /** Print de uma visita num aparelho; `$moment` é `first` (ao acessar) ou `second` (10 s depois). */
    public function ofCapture(LookalikeCapture $capture, string $device, string $moment): ?string
    {
        return $capture->shotFile($device, $moment);
    }

    /** Print de referência do site do cliente: a página (a partir de 1) num aparelho. */
    public function ofReference(LookalikeWatch $watch, int $page, string $device): ?string
    {
        return LookalikeCapture::fileIn($watch->referencePages()[$page - 1]['shots'][$device] ?? null);
    }

    /** A foto do aviso, que o Slack, o Discord e o Telegram buscam por link assinado. */
    public function ofAlert(LookalikeAlert $alert): ?string
    {
        return LookalikeCapture::fileIn($alert->photo['path'] ?? null);
    }
}
