<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\ShowNewWebhookRequest;
use App\Services\Alerts\Webhook\WebhookConnector;
use Illuminate\View\View;

/** /alertas/webhooks/novo: nome, URL e autenticação do webhook novo, com o formato do JSON. */
class ShowNewWebhookController extends Controller
{
    public function __construct(private WebhookConnector $webhooks) {}

    public function __invoke(ShowNewWebhookRequest $request): View
    {
        return view('alerts.webhooks.create', $this->webhooks->newPage($request->user(), $request->channelScope()));
    }
}
