<?php

namespace App\Http\Requests\Alerts\Discord;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;
use App\Services\Alerts\Discord\DiscordClient;

class ConnectDiscordRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'discord';
    }

    /**
     * Sem o bot configurado não há o que conectar; a comunidade só existe com o servidor do Security Monitor configurado. Na empresa,
     * só onde a conta configura (a mensagem direta o conector recusa, P1).
     */
    public function authorize(): bool
    {
        return DiscordClient::configured()
            && ($this->route('modo') !== 'comunidade' || DiscordClient::homeGuild())
            && $this->targetsManagedChannel();
    }
}
