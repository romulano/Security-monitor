<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\StoreAlertRequest;
use App\Services\Alerts\AlertPanel;
use App\Services\Alerts\InterviewService;
use Illuminate\Http\RedirectResponse;

/** Alerta novo, para outro produto ou site; pode começar com as respostas de um existente. */
class StoreAlertController extends Controller
{
    public function __construct(
        private AlertPanel $alerts,
        private InterviewService $interview,
    ) {}

    public function __invoke(StoreAlertRequest $request): RedirectResponse
    {
        $this->alerts->ensureRoomFor($request->user());

        $from = $request->copyFrom();
        $profile = $this->interview->create($request->user(), $from);

        return redirect()->route('alerts.interview', $profile->urlParams(['passo' => 1]))->with('status', $from
            ? 'Alerta novo, com as respostas de “'.$from->displayName().'”. Dê um nome a ele e ajuste o que muda neste produto ou site.'
            : 'Alerta novo. Dê um nome a ele para diferenciar dos outros.');
    }
}
