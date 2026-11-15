<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ChangeRadarSectorRequest;
use App\Services\Company\ItemSectors;
use Illuminate\Http\RedirectResponse;

class ChangeRadarSectorController extends Controller
{
    public function __construct(private ItemSectors $sectors) {}

    public function __invoke(ChangeRadarSectorRequest $request): RedirectResponse
    {
        $watch = $request->watch();
        $this->sectors->move($request->user(), $watch, $request->sectorId(), 'Radar', $watch->domain?->domain ?? 'Radar '.$watch->id);

        return redirect()->route('alerts.lookalikes', $watch->urlParams())->with('status', 'Setor do Radar trocado.');
    }
}
