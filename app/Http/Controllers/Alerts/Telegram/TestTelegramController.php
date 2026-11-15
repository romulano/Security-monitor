<?php

namespace App\Http\Controllers\Alerts\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Telegram\TestTelegramRequest;
use App\Services\Alerts\Notifier;
use Illuminate\Http\RedirectResponse;

class TestTelegramController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function __invoke(TestTelegramRequest $request): RedirectResponse
    {
        $this->notifier->test($request->channel());

        return back()->with('status', 'Mensagem de teste enviada. Se não aparecer no Telegram em alguns segundos, veja os últimos envios abaixo.');
    }
}
