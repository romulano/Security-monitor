<?php

namespace App\Http\Controllers\Alerts\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Telegram\ShowTelegramRequest;
use App\Services\Alerts\Telegram\TelegramConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * /alertas/telegram: conectar e escolher o que receber. O cliente gera um
 * link de uso único que abre o Telegram com o /start do código, numa
 * conversa privada com o bot ou num grupo; quem conecta é o bot, ao receber
 * o /start (UpdateHandler). Enquanto isso, a página pergunta se o link já foi usado.
 */
class ShowTelegramController extends Controller
{
    public function __construct(private TelegramConnector $telegram) {}

    public function __invoke(ShowTelegramRequest $request): View|RedirectResponse
    {
        // Na empresa, pela tela de conectar (`?setor=`): o canal novo que o grupo acabou de conectar.
        $channel = $request->channel() ?? $request->justConnected();
        $session = $request->session();

        // O link desta sessão saiu da espera: ou o bot conectou a conversa, ou o tempo acabou.
        $connected = $this->telegram->finishedLink($channel, $session);

        if ($connected !== null) {
            $request->session()->now(...$connected
                ? ['status', 'Telegram conectado. Mandamos uma mensagem de teste para a conversa.']
                : ['error', 'O link de conexão expirou. Gere outro abaixo.']);
        }

        return view('alerts.telegram', $this->telegram->page($request->user(), $request->channel(), $session, $request->changing())
            + ['target' => $request->target(), 'where' => $request->where()]);
    }
}
