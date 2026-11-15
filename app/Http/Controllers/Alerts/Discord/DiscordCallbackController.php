<?php

namespace App\Http\Controllers\Alerts\Discord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Discord\DiscordCallbackRequest;
use App\Services\Alerts\Discord\DiscordConnector;
use Illuminate\Http\RedirectResponse;

class DiscordCallbackController extends Controller
{
    public function __construct(private DiscordConnector $discord) {}

    public function __invoke(DiscordCallbackRequest $request): RedirectResponse
    {
        $channel = $this->discord->finishOAuth($request->user(), $request->session(), $request->state(), $request->code());

        // Mensagem direta recusada sem servidor em comum com o bot: a tela oferece a comunidade do Security Monitor.
        if (! $channel) {
            return redirect()->route('alerts.discord')->with('discord_join_offer', true);
        }

        if ($channel->method === 'dm') {
            return redirect()->to($channel->settingsUrl())->with('status', 'Mensagem direta conectada. Mandamos um teste para você no Discord.');
        }

        $name = $channel->discord_guild_name;

        return redirect()->to($channel->settingsUrl())->with('status', 'Bot do Security Monitor adicionado'.($name ? " a {$name}" : '').'. Agora escolha o canal onde os alertas vão chegar.');
    }
}
