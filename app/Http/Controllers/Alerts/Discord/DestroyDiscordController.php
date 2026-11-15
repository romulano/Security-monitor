<?php

namespace App\Http\Controllers\Alerts\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Discord\DestroyDiscordRequest;
use App\Services\Alerts\Discord\DiscordConnector;
use Illuminate\Http\RedirectResponse;

class DestroyDiscordController extends Controller
{
    public function __construct(private DiscordConnector $discord) {}

    public function __invoke(DestroyDiscordRequest $request): RedirectResponse
    {
        $this->discord->disconnect($request->channel(), $request->user());

        return redirect()->route('alerts.discord')->with('status', 'Discord desconectado. Os alertas continuam guardados em Alertas.');
    }
}
