<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\TestWebhookRequest;
use App\Services\Alerts\Webhook\WebhookConnector;
use Illuminate\Http\RedirectResponse;

class TestWebhookController extends Controller
{
    public function __construct(private WebhookConnector $webhooks) {}

    public function __invoke(TestWebhookRequest $request): RedirectResponse
    {
        $this->webhooks->test($request->channel());

        return back()->with('status', 'Teste enviado. O resultado (código HTTP e tempo de resposta) aparece nos últimos envios, abaixo.');
    }
}
