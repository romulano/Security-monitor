<?php

namespace App\Http\Requests\Alerts\Webhooks;

use App\Http\Requests\Alerts\ChannelPreferencesRequest;
use App\Models\NotificationChannel;

/** As preferências (na hora, horário silencioso, resumo), o mesmo formulário dos apps. */
class UpdateWebhookRequest extends ChannelPreferencesRequest
{
    private ?NotificationChannel $channel = null;

    public function channel(): ?NotificationChannel
    {
        // Como o WebhookRequest: só quem configura o canal (E5a2).
        return $this->channel ??= NotificationChannel::query()->manageableBy($this->user())->where('type', 'webhook')->find((int) $this->route('webhook'));
    }
}
