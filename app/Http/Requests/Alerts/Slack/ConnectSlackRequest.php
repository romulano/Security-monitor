<?php

namespace App\Http\Requests\Alerts\Slack;

use App\Http\Requests\Concerns\TargetsChannel;
use App\Http\Requests\Request;
use App\Services\Alerts\Slack\SlackClient;

class ConnectSlackRequest extends Request
{
    use TargetsChannel;

    protected function channelApp(): string
    {
        return 'slack';
    }

    /** Sem o app do Security Monitor no Slack configurado, o botão não existe; na empresa, só onde a conta configura. */
    public function authorize(): bool
    {
        return SlackClient::oauthConfigured() && $this->targetsManagedChannel();
    }
}
