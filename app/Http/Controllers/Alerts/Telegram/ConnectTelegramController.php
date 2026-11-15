<?php

namespace App\Http\Controllers\Alerts\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Telegram\ConnectTelegramRequest;
use App\Services\Alerts\Telegram\TelegramConnector;
use Illuminate\Http\RedirectResponse;

/** "Numa conversa privada" (`privado`) ou "Num grupo" (`grupo`): gera o link e mostra o botão que abre o Telegram. */
class ConnectTelegramController extends Controller
{
    public function __construct(private TelegramConnector $telegram) {}

    public function __invoke(ConnectTelegramRequest $request): RedirectResponse
    {
        $this->telegram->startLink($request->user(), $request->session(), $request->group(), ...$request->connectorTarget());

        return redirect()->route('alerts.telegram', $request->target());
    }
}
