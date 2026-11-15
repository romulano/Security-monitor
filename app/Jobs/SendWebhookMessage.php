<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\Alerts\Webhook\WebhookClient;
use App\Services\Alerts\Webhook\WebhookHealth;

/**
 * Entrega no webhook do cliente (o JSON assinado). Tenta de novo só o que pode
 * mudar sozinho (5xx, tempo esgotado, rede) em 1, 5 e 30 minutos; 4xx não repete,
 * menos o 429, que espera o Retry-After. As entregas que falham de vez, seguidas,
 * param o webhook na 5ª (WebhookHealth).
 */
class SendWebhookMessage extends SendChannelMessage
{
    public function handle(WebhookClient $webhooks): void
    {
        $this->deliver(fn ($channel, array $payload) => $webhooks->post($channel, $payload));
    }

    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    protected function app(): string
    {
        return 'Webhook';
    }

    protected function delivered(NotificationDelivery $delivery): void
    {
        app(WebhookHealth::class)->delivered($delivery);
    }

    protected function gaveUp(NotificationDelivery $delivery): void
    {
        app(WebhookHealth::class)->failed($delivery);
    }
}
