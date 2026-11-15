<?php

namespace App\Http\Controllers\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\ResendAlertRequest;
use App\Models\AlertMatch;
use App\Services\Alerts\AlertPanel;
use Illuminate\Http\RedirectResponse;

/** Manda de novo um alerta do histórico para os canais ativos (Slack, Discord, Telegram), mesmo que já tenha ido. */
class ResendAlertController extends Controller
{
    public function __construct(private AlertPanel $alerts) {}

    public function __invoke(ResendAlertRequest $request, AlertMatch $match): RedirectResponse
    {
        $channels = $this->alerts->resend($match, $request->user());

        if ($channels === null) {
            return back()->with('status', 'Esse alerta já está na fila de envio.');
        }

        return back()->with('status', 'Alerta reenviado para '.$channels->map(fn ($c) => 'o '.$c->appName())->join(', ', ' e ').'.');
    }
}
