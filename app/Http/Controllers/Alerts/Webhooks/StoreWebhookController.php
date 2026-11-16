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

        // Instalação de um dono só: o domínio da URL já nasce comprovado, nunca pendente.
        return redirect()->route('alerts.webhooks.show', $channel)->with('status', 'Webhook cadastrado. Mande um teste para conferir o seu servidor.');
    }
}
