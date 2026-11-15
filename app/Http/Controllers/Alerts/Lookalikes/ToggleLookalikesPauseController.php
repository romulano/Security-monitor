<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ToggleLookalikesPauseRequest;
use App\Services\Lookalike\LookalikeRadar;
use Illuminate\Http\RedirectResponse;

class ToggleLookalikesPauseController extends Controller
{
    public function __construct(private LookalikeRadar $radar) {}

    public function __invoke(ToggleLookalikesPauseRequest $request): RedirectResponse
    {
        $paused = $this->radar->togglePause($request->watch());

        return back()->with('status', $paused ? 'Radar pausado. O que já foi achado continua aqui.' : 'Radar retomado.');
    }
}
