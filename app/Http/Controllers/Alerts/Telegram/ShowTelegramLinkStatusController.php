<?php

namespace App\Http\Controllers\Alerts\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Telegram\ShowTelegramLinkStatusRequest;
use App\Services\Alerts\Telegram\TelegramConnector;
use Illuminate\Http\JsonResponse;

/** A página pergunta enquanto espera o /start: quando o link é usado ou expira, ela recarrega. */
class ShowTelegramLinkStatusController extends Controller
{
    public function __construct(private TelegramConnector $telegram) {}

    public function __invoke(ShowTelegramLinkStatusRequest $request): JsonResponse
    {
        return response()->json(['waiting' => $this->telegram->waiting($request->session())]);
    }
}
