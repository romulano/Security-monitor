<?php

namespace App\Http\Controllers\Alerts\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Discord\ConnectDiscordRequest;
use App\Services\Alerts\Discord\DiscordConnector;
use Illuminate\Http\RedirectResponse;

/**
 * "Adicionar a um servidor" (`canal`), "Receber em mensagem direta" (`dm`)
 * ou "Entrar na comunidade do Security Monitor" (`comunidade`).
 */
class ConnectDiscordController extends Controller
{
    public function __construct(private DiscordConnector $discord) {}

    public function __invoke(ConnectDiscordRequest $request, string $modo): RedirectResponse
    {
        return redirect()->away($this->discord->authorizeUrl($modo, $request->session(), $request->user(), ...$request->connectorTarget()));
    }
}
