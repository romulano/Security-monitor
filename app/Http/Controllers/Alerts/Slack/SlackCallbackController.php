<?php

namespace App\Http\Controllers\Alerts\Slack;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Slack\SlackCallbackRequest;
use App\Services\Alerts\Slack\SlackConnector;
use Illuminate\Http\RedirectResponse;

class SlackCallbackController extends Controller
{
    public function __construct(private SlackConnector $slack) {}

    public function __invoke(SlackCallbackRequest $request): RedirectResponse
    {
        $channel = $this->slack->finishOAuth($request->user(), $request->session(), $request->state(), $request->code());

        return redirect()->to($channel->settingsUrl())->with('status', 'Slack conectado'.($channel->slack_channel ? " em {$channel->slack_channel}" : '').'. Mandamos uma mensagem de teste.');
    }
}
