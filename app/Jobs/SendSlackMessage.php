<?php

namespace App\Jobs;

use App\Services\Alerts\Slack\SlackClient;

/** Entrega no Slack (webhook do canal, do app ou de um fluxo de trabalho). */
class SendSlackMessage extends SendChannelMessage
{
    public function handle(SlackClient $slack): void
    {
        $this->deliver(fn ($channel, array $payload) => $slack->post($channel, $payload));
    }

    protected function app(): string
    {
        return 'Slack';
    }
}
