<?php

namespace App\Http\Controllers\Alerts\Center;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Center\ToggleChannelPauseRequest;
use App\Models\NotificationChannel;
use App\Services\Alerts\AlertCenter;
use Illuminate\Http\RedirectResponse;

/** Pausa ou retoma o envio para um app (Slack, Discord, Telegram) sem desconectá-lo. */
class ToggleChannelPauseController extends Controller
{
    public function __construct(private AlertCenter $center) {}

    public function __invoke(ToggleChannelPauseRequest $request, NotificationChannel $channel): RedirectResponse
    {
        $app = $channel->appName();

        return back()->with('status', $this->center->togglePause($channel)
            ? "Envio para o {$app} pausado. Nada sai para lá até você retomar; os alertas continuam guardados no Security Monitor."
            : "Envio para o {$app} retomado.");
    }
}
