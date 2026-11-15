<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\ShowWebhookRequest;
use App\Services\Alerts\Webhook\WebhookConnector;
use Illuminate\View\View;

/**
 * /alertas/webhooks/{webhook}: a posse do domínio, o segredo (inteiro só logo depois de
 * criado), o que recebe, as preferências, o formato do JSON e os últimos envios.
 */
class ShowWebhookController extends Controller
{
    public function __construct(private WebhookConnector $webhooks) {}

    public function __invoke(ShowWebhookRequest $request): View
    {
        return view('alerts.webhooks.show', $this->webhooks->page($request->user(), $request->channel(), $request->session()));
    }
}
