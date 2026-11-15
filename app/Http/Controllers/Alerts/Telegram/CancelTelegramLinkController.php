<?php

namespace App\Http\Controllers\Alerts\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Telegram\CancelTelegramLinkRequest;
use App\Services\Alerts\Telegram\TelegramConnector;
use Illuminate\Http\RedirectResponse;

/** Desiste do link: ele deixa de valer na hora. */
class CancelTelegramLinkController extends Controller
{
    public function __construct(private TelegramConnector $telegram) {}

    public function __invoke(CancelTelegramLinkRequest $request): RedirectResponse
    {
        $this->telegram->cancelLink($request->session());

        return redirect()->route('alerts.telegram');
    }
}
