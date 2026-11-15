<?php

namespace App\Http\Controllers\Alerts\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Discord\ShowDiscordRequest;
use App\Services\Alerts\Discord\DiscordConnector;
use Illuminate\View\View;

/**
 * /alertas/discord: conectar e escolher o que receber. Três jeitos:
 * o bot num canal do servidor (para a equipe), o bot em mensagem direta (só
 * para quem conectou) ou um webhook colado, que não precisa do bot.
 */
class ShowDiscordController extends Controller
{
    public function __construct(private DiscordConnector $discord) {}

    public function __invoke(ShowDiscordRequest $request): View
    {
        return view('alerts.discord', $this->discord->page($request->user(), $request->channel(), $request->changing(), $request->pickChannel()) + ['target' => $request->target(), 'where' => $request->where()]);
    }
}
