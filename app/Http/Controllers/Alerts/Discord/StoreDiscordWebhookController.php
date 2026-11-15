<?php

namespace App\Http\Controllers\Alerts\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Discord\StoreDiscordWebhookRequest;
use App\Services\Alerts\Discord\DiscordConnector;
use Illuminate\Http\RedirectResponse;

class StoreDiscordWebhookController extends Controller
{
    public function __construct(private DiscordConnector $discord) {}

    public function __invoke(StoreDiscordWebhookRequest $request): RedirectResponse
    {
        $channel = $this->discord->saveWebhook($request->user(), $request->webhookUrl(), ...$request->connectorTarget());

        return redirect()->to($channel->settingsUrl())->with('status', 'Webhook salvo. Mandamos uma mensagem de teste para o canal.');
    }
}
