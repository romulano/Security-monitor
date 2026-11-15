<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SendReauthCodeRequest;
use App\Services\Auth\ReauthCodes;
use Illuminate\Http\RedirectResponse;

/** "Receber um código no Telegram": manda o código da reautenticação ao app escolhido. */
class SendReauthCodeController extends Controller
{
    public function __construct(private ReauthCodes $codes) {}

    public function __invoke(SendReauthCodeRequest $request): RedirectResponse
    {
        $channel = $this->codes->send($request->user(), $request->channelId(), $request->session(), $request->context());

        return back()->with('status', "Mandamos o código para o {$channel->appName()} ({$channel->label()}). Ele vale por 10 minutos.");
    }
}
