<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\UpdateWebhookEndpointRequest;
use App\Services\Alerts\Webhook\WebhookConnector;
use Illuminate\Http\RedirectResponse;

class UpdateWebhookEndpointController extends Controller
{
    public function __construct(private WebhookConnector $webhooks) {}

    public function __invoke(UpdateWebhookEndpointRequest $request): RedirectResponse
    {
        $channel = $request->channel();
        $this->webhooks->updateEndpoint($channel, $request->user(), (string) $request->input('nome'), $request->input('url'));

        return back()->with('status', $channel->status === 'pending'
            ? 'Endereço salvo. O domínio novo ainda não foi comprovado: até lá, o Security Monitor não chama o endereço.'
            : 'Endereço salvo.');
    }
}
