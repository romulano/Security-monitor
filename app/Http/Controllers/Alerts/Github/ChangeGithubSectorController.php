<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\ChangeGithubSectorRequest;
use App\Services\Company\ItemSectors;
use Illuminate\Http\RedirectResponse;

class ChangeGithubSectorController extends Controller
{
    public function __construct(private ItemSectors $sectors) {}

    public function __invoke(ChangeGithubSectorRequest $request): RedirectResponse
    {
        $monitor = $request->monitor();
        $this->sectors->move($request->user(), $monitor, $request->sectorId(), 'GitHub', $monitor->displayName());

        return redirect()->route('alerts.github', $monitor->urlParams())->with('status', 'Setor do monitoramento trocado.');
    }
}
