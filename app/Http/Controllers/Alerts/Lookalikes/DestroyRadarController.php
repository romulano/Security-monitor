<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\DestroyRadarRequest;
use App\Services\Lookalike\RadarInterview;
use Illuminate\Http\RedirectResponse;

/** Exclui um Radar (ou o rascunho dele), com os achados e os prints. */
class DestroyRadarController extends Controller
{
    public function __construct(private RadarInterview $interview) {}

    public function __invoke(DestroyRadarRequest $request): RedirectResponse
    {
        $watch = $request->watch();
        $name = $watch->domain?->domain;
        $this->interview->delete($watch);

        return redirect()->route('alerts.lookalikes')->with('status', 'Radar'.($name ? " de {$name}" : '').' excluído.');
    }
}
