<?php

namespace App\Http\Controllers\Alerts\Interview;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Interview\ShowInterviewStepRequest;
use App\Services\Alerts\InterviewService;
use Illuminate\View\View;

/**
 * /alertas/entrevista/{passo}: os 7 passos, com rascunho salvo a cada um.
 * `?alerta=ID` diz qual alerta do cliente está sendo editado; sem ele, o primeiro.
 */
class ShowInterviewStepController extends Controller
{
    public function __construct(private InterviewService $interview) {}

    public function __invoke(ShowInterviewStepRequest $request, int $passo): View
    {
        // Alerta novo de empresa: o passo 1 com o setor, sem rascunho até gravar.
        if ($request->startsNew()) {
            return view('alerts.interview', $this->interview->stepPage($this->interview->blank(), $passo) + ['newAlert' => $this->interview->sectorChoice($request->user())]);
        }

        $profile = $this->interview->profileFor($request->user(), $request->alertId());

        return view('alerts.interview', $this->interview->stepPage($profile, $passo));
    }
}
