<?php

namespace App\Http\Controllers\Alerts\Slack;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Slack\ConnectSlackRequest;
use App\Services\Alerts\Slack\SlackConnector;
use Illuminate\Http\RedirectResponse;

/** "Adicionar ao Slack": o cliente escolhe o canal na tela do Slack. */
class ConnectSlackController extends Controller
{
    public function __construct(private SlackConnector $slack) {}

    public function __invoke(ConnectSlackRequest $request): RedirectResponse
    {
        return redirect()->away($this->slack->authorizeUrl($request->session(), $request->user(), ...$request->connectorTarget()));
    }
}
