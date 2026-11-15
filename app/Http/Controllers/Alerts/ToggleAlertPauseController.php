<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\ToggleAlertPauseRequest;
use App\Services\Alerts\AlertPanel;
use Illuminate\Http\RedirectResponse;

class ToggleAlertPauseController extends Controller
{
    public function __construct(private AlertPanel $alerts) {}

    public function __invoke(ToggleAlertPauseRequest $request): RedirectResponse
    {
        $profile = $request->profile();
        $paused = $this->alerts->togglePause($profile);

        $name = $profile->hasSiblings() ? ' “'.$profile->displayName().'”' : '';

        return back()->with('status', $paused
            ? "Alerta{$name} pausado. Nada dele vai para os apps conectados até você retomar."
            : "Alerta{$name} retomado.");
    }
}
