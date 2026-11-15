<?php

namespace App\Http\Controllers\Alerts\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Webhooks\VerifyWebhookDomainRequest;
use App\Services\Alerts\Webhook\WebhookConnector;
use Illuminate\Http\RedirectResponse;

/** Botão "Verificar": procura o TXT de posse (ou a prova que a conta já tem em outro serviço). */
class VerifyWebhookDomainController extends Controller
{
    public function __construct(private WebhookConnector $webhooks) {}

    public function __invoke(VerifyWebhookDomainRequest $request): RedirectResponse
    {
        $this->webhooks->verify($request->channel(), $request->user());

        return back()->with('status', 'Domínio comprovado. O webhook já pode receber: mande um teste para conferir o seu servidor.');
    }
}
