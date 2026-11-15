<?php

namespace App\Http\Controllers\Alerts\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Telegram\DestroyTelegramRequest;
use App\Services\Alerts\Telegram\TelegramConnector;
use Illuminate\Http\RedirectResponse;

class DestroyTelegramController extends Controller
{
    public function __construct(private TelegramConnector $telegram) {}

    public function __invoke(DestroyTelegramRequest $request): RedirectResponse
    {
        $this->telegram->disconnect($request->channel(), $request->user());

        return redirect()->route($request->user()->isCompanyAccount() ? 'alerts.notifiers' : 'alerts.telegram')->with('status', 'Telegram desconectado. Os alertas continuam guardados em Alertas.');
    }
}
