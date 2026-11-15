<?php

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\UpdateAlertsConfigRequest;
use App\Services\Admin\AlertsOverview;
use Illuminate\Http\RedirectResponse;

class UpdateAlertsConfigController extends Controller
{
    public function __construct(private AlertsOverview $overview) {}

    public function __invoke(UpdateAlertsConfigRequest $request): RedirectResponse
    {
        $this->overview->saveConfig($request->config());

        return back()->with('status', 'Configurações dos alertas salvas.');
    }
}
