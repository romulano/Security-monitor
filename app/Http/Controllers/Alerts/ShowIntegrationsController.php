<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\ShowIntegrationsRequest;
use App\Services\Alerts\IntegrationsPanel;
use Illuminate\View\View;

/** /alertas/integracao: o que vigiar (Notícias, Radar de domínios, GitHub), cada um com a sua entrevista. */
class ShowIntegrationsController extends Controller
{
    public function __construct(private IntegrationsPanel $integrations) {}

    public function __invoke(ShowIntegrationsRequest $request): View
    {
        return view('alerts.integrations', $this->integrations->page($request->user()));
    }
}
