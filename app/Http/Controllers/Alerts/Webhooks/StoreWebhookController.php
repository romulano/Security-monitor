<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\StoreWebhookRequest;
use App\Services\Alerts\Webhook\WebhookConnector;
use Illuminate\Http\RedirectResponse;

class StoreWebhookController extends Controller
{
    public function __construct(private WebhookConnector $webhooks) {}

    public function __invoke(StoreWebhookRequest $request): RedirectResponse
    {
        $channel = $this->webhooks->create(
            $request->user(),
            (string) $request->input('nome'),
            (string) $request->input('url'),
            (string) $request->input('autenticacao'),
            $request->input('segredo'),
            $request->session(),
        );

        return redirect()->route('alerts.webhooks.show', $channel)->with('status', $channel->status === 'pending'
            ? 'Webhook cadastrado. Falta comprovar o domínio: até lá, o Security Monitor não chama o endereço.'
            : 'Webhook cadastrado com o domínio que você já comprovou. Mande um teste para conferir o seu servidor.');
    }
}
