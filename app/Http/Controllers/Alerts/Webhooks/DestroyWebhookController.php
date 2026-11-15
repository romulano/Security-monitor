<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\DestroyWebhookRequest;
use App\Services\Alerts\Webhook\WebhookConnector;
use Illuminate\Http\RedirectResponse;

class DestroyWebhookController extends Controller
{
    public function __construct(private WebhookConnector $webhooks) {}

    public function __invoke(DestroyWebhookRequest $request): RedirectResponse
    {
        $channel = $request->channel();
        $name = $channel->appName();
        $this->webhooks->disconnect($channel);

        return redirect()->route('alerts.notifiers')->with('status', "{$name} excluído. Os alertas continuam guardados em Alertas.");
    }
}
