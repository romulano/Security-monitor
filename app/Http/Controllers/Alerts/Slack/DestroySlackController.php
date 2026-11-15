<?php

namespace App\Http\Controllers\Alerts\Slack;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Slack\DestroySlackRequest;
use App\Services\Alerts\Slack\SlackConnector;
use Illuminate\Http\RedirectResponse;

class DestroySlackController extends Controller
{
    public function __construct(private SlackConnector $slack) {}

    public function __invoke(DestroySlackRequest $request): RedirectResponse
    {
        $this->slack->disconnect($request->channel(), $request->user());

        return redirect()->route('alerts.slack')->with('status', 'Slack desconectado. Os alertas continuam guardados em Alertas.');
    }
}
