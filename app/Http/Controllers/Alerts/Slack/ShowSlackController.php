<?php

namespace App\Http\Controllers\Alerts\Slack;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Slack\ShowSlackRequest;
use App\Services\Alerts\Slack\SlackConnector;
use Illuminate\View\View;

/** /alertas/slack: conectar o canal e escolher o que receber. */
class ShowSlackController extends Controller
{
    public function __construct(private SlackConnector $slack) {}

    public function __invoke(ShowSlackRequest $request): View
    {
        return view('alerts.slack', $this->slack->page($request->user(), $request->channel(), $request->changing()) + ['target' => $request->target(), 'where' => $request->where()]);
    }
}
