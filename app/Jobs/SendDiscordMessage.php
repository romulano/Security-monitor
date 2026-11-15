<?php

namespace App\Jobs;

use App\Services\Alerts\Discord\DiscordClient;

/** Entrega no Discord: pelo bot (canal do servidor ou mensagem direta) ou por webhook. */
class SendDiscordMessage extends SendChannelMessage
{
    public function handle(DiscordClient $discord): void
    {
        $this->deliver(fn ($channel, array $payload) => $discord->post($channel, $payload));
    }

    protected function app(): string
    {
        return 'Discord';
    }
}
