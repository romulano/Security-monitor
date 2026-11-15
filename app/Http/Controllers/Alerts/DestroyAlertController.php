<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\DestroyAlertRequest;
use App\Services\Alerts\AlertPanel;
use Illuminate\Http\RedirectResponse;

class DestroyAlertController extends Controller
{
    public function __construct(private AlertPanel $alerts) {}

    public function __invoke(DestroyAlertRequest $request): RedirectResponse
    {
        $profile = $request->profile();

        if (! $profile) {
            return redirect()->route('alerts.news');
        }

        $name = $profile->displayName();
        $this->alerts->delete($profile);

        return redirect()->route('alerts.news')->with('status', "Alerta “{$name}” excluído, com respostas, domínio e histórico.");
    }
}
