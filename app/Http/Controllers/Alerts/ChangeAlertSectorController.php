<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\ChangeAlertSectorRequest;
use App\Services\Company\ItemSectors;
use Illuminate\Http\RedirectResponse;

/** Troca o setor de um alerta de Notícias da empresa (DESENHO-5.2 §2.1), com a Atividade registrando o de/para. */
class ChangeAlertSectorController extends Controller
{
    public function __construct(private ItemSectors $sectors) {}

    public function __invoke(ChangeAlertSectorRequest $request): RedirectResponse
    {
        $profile = $request->profile();
        $this->sectors->move($request->user(), $profile, $request->sectorId(), 'Notícias', $profile->displayName());

        return redirect()->route('alerts.news', $profile->urlParams())->with('status', 'Setor do alerta trocado.');
    }
}
