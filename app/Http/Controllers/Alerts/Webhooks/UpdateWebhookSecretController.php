<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\UpdateWebhookSecretRequest;
use App\Services\Alerts\Webhook\WebhookConnector;
use Illuminate\Http\RedirectResponse;

class UpdateWebhookSecretController extends Controller
{
    public function __construct(private WebhookConnector $webhooks) {}

    public function __invoke(UpdateWebhookSecretRequest $request): RedirectResponse
    {
        $auth = (string) $request->input('autenticacao');
        $this->webhooks->changeSecret($request->channel(), $request->user(), $auth, $request->input('segredo'), $request->session());

        return back()->with('status', match ($auth) {
            'gerar' => 'Segredo novo gerado. O anterior parou de valer: troque no seu servidor.',
            'escolher' => 'Segredo trocado. O anterior parou de valer: troque no seu servidor.',
            default => 'Autenticação desligada: as notificações saem sem os cabeçalhos Security-Monitor-Notification e Security-Monitor-Signature.',
        });
    }
}
