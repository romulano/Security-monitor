<?php

namespace App\Http\Requests\Alerts\Slack;

use App\Http\Requests\Alerts\ChannelPreferencesRequest;
use App\Http\Requests\Concerns\TargetsChannel;

/** As preferências do canal desta tela (ChannelPreferencesRequest: sem canal, 404). */
class UpdateSlackRequest extends ChannelPreferencesRequest
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'slack';
    }
}
