<?php

namespace App\Http\Requests\Alerts\Webhooks;

use App\Http\Requests\Request;
use App\Models\NotificationChannel;

/**
 * Base das telas de um webhook (`{webhook}`): só quem o configura — o dono do pessoal; o da empresa, quem administra
 * (gerais) ou quem responde pelo setor dele (E5a2, Visibility::manages). O membro vê o canal em Notificadores, mas a
 * página dele (URL, segredo) dá 404, como o de outra conta.
 */
abstract class WebhookRequest extends Request
{
    private ?NotificationChannel $channel = null;

    public function authorize(): bool
    {
        return $this->channel() !== null;
    }

    /** Sempre do banco, não a relação já carregada no usuário. */
    public function channel(): ?NotificationChannel
    {
        return $this->channel ??= NotificationChannel::query()->manageableBy($this->user())->where('type', 'webhook')->find((int) $this->route('webhook'));
    }
}
