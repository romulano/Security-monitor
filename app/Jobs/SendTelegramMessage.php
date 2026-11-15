<?php

namespace App\Jobs;

use App\Services\Alerts\Telegram\TelegramClient;

/** Entrega no Telegram, pelo bot: na conversa privada ou no grupo conectado. */
class SendTelegramMessage extends SendChannelMessage
{
    public function handle(TelegramClient $telegram): void
    {
        $this->deliver(fn ($channel, array $payload) => $telegram->post($channel, $payload));
    }

    protected function app(): string
    {
        return 'Telegram';
    }
}
