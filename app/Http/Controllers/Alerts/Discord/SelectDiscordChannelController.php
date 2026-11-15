<?php

namespace App\Http\Controllers\Alerts\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Discord\SelectDiscordChannelRequest;
use App\Services\Alerts\Discord\DiscordConnector;
use Illuminate\Http\RedirectResponse;

class SelectDiscordChannelController extends Controller
{
    public function __construct(private DiscordConnector $discord) {}

    public function __invoke(SelectDiscordChannelRequest $request): RedirectResponse
    {
        $name = $this->discord->selectChannel($request->channel(), $request->channelId());

        // Canal que não é deste servidor: erro do campo, sem o old() (a tela volta marcando o canal atual).
        if ($name === null) {
            return back()->withErrors(['channel_id' => 'Escolha um canal da lista.']);
        }

        return redirect()->to($request->channel()->settingsUrl())->with('status', "Canal #{$name} escolhido. Mandamos uma mensagem de teste para ele.");
    }
}
