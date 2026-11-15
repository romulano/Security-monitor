<?php

namespace App\Http\Controllers\Alerts\Slack;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Slack\StoreSlackWebhookRequest;
use App\Services\Alerts\Slack\SlackConnector;
use Illuminate\Http\RedirectResponse;

class StoreSlackWebhookController extends Controller
{
    public function __construct(private SlackConnector $slack) {}

    public function __invoke(StoreSlackWebhookRequest $request): RedirectResponse
    {
        $channel = $this->slack->saveWebhook($request->user(), $request->webhookUrl(), ...$request->connectorTarget());

        return redirect()->to($channel->settingsUrl())->with('status', 'Webhook salvo. Mandamos uma mensagem de teste para o canal.');
    }
}
