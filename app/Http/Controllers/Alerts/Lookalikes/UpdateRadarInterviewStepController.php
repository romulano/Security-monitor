<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\UpdateRadarInterviewStepRequest;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Http\RedirectResponse;

/** Grava o passo 1 (domínio; o Radar nasce aqui) ou o 2 (marca) e segue para o próximo. */
class UpdateRadarInterviewStepController extends Controller
{
    public function __construct(private RadarInterview $interview) {}

    public function __invoke(UpdateRadarInterviewStepRequest $request): RedirectResponse
    {
        if ($request->step() === 1) {
            $watch = $this->interview->saveDomain($request->user(), $request->watch(), $request->provenId(), $request->typed());
        } else {
            $watch = $request->watch();
            $this->interview->saveBrand($watch, $request->company(), $request->brands());
        }

        return redirect()->route('alerts.lookalikes.interview', ['passo' => $request->step() + 1, 'radar' => $watch->id]);
    }
}
