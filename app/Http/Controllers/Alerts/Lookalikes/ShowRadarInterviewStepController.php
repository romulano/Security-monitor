<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ShowRadarInterviewStepRequest;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Um passo da entrevista do Radar (domínio, marca, posse e ativação). Passo ainda não alcançado volta ao último. */
class ShowRadarInterviewStepController extends Controller
{
    public function __construct(private RadarInterview $interview) {}

    public function __invoke(ShowRadarInterviewStepRequest $request): View|RedirectResponse
    {
        $watch = $request->watch();
        $reachable = $this->interview->reachable($watch);

        if ($request->step() > $reachable) {
            return redirect()->route('alerts.lookalikes.interview', array_filter(['passo' => $reachable, 'radar' => $watch?->id]));
        }

        return view('alerts.lookalikes.interview', $this->interview->stepPage($request->user(), $watch, $request->step()));
    }
}
