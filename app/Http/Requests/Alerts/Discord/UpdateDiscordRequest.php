<?php

namespace App\Http\Requests\Alerts\Discord;

use App\Http\Requests\Alerts\ChannelPreferencesRequest;
use App\Http\Requests\Concerns\TargetsChannel;

/** As preferências do canal desta tela (ChannelPreferencesRequest: sem canal, 404). */
class UpdateDiscordRequest extends ChannelPreferencesRequest
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'discord';
    }
}
