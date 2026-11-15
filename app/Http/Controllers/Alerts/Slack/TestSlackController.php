<?php

namespace App\Http\Controllers\Alerts\Slack;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Slack\TestSlackRequest;
use App\Services\Alerts\Notifier;
use Illuminate\Http\RedirectResponse;

class TestSlackController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function __invoke(TestSlackRequest $request): RedirectResponse
    {
        $this->notifier->test($request->channel());

        return back()->with('status', 'Mensagem de teste enviada. Se não aparecer no canal em alguns segundos, veja os últimos envios abaixo.');
    }
}
