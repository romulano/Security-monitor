<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ActivateRadarRequest;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Http\RedirectResponse;

/** "Ativar o Radar" no passo 3 (ou "Salvar", depois de ativado): o aceite dos prints e a ativação. */
class ActivateRadarController extends Controller
{
    public function __construct(private RadarInterview $interview) {}

    public function __invoke(ActivateRadarRequest $request): RedirectResponse
    {
        $watch = $request->watch();
        $result = $this->interview->activate($watch, $request->consent(), $request->ip());

        return redirect()->route('alerts.lookalikes', $watch->urlParams())->with('status', match ($result) {
            'started' => 'Radar ativado. A primeira varredura confere centenas de nomes no DNS e leva uns minutos.',
            default => 'Radar salvo.',
        });
    }
}
