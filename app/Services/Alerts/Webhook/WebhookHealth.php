<?php

namespace App\Services\Alerts\Webhook;

use App\Mail\WebhookPaused;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Mail\Outbox;

/**
 * As falhas seguidas de um webhook. Cada entrega que falha de vez (sem mais
 * tentativa) conta uma; uma entrega feita zera. Na 5ª seguida o envio para,
 * como o app que recusa o envio ("broken"): o webhook não recebe mais nada além
 * do teste, e o teste que der certo põe o webhook de volta. O cliente é avisado
 * uma vez, por e-mail: o webhook pode ser o único notificador da conta, e o
 * e-mail dela é confirmado.
 *
 * O teste não conta como falha: é com ele que o cliente confere o servidor
 * enquanto configura o middleware.
 */
class WebhookHealth
{
    public function __construct(private Outbox $outbox) {}

    public function delivered(NotificationDelivery $delivery): void
    {
        NotificationChannel::query()->whereKey($delivery->channel_id)->where('failures', '>', 0)->update(['failures' => 0]);
    }

    public function failed(NotificationDelivery $delivery): void
    {
        if ($delivery->kind === 'test') {
            return;
        }

        $max = (int) config('secmon.alerts.webhook.max_failures', 5);
        $webhook = fn () => NotificationChannel::query()->whereKey($delivery->channel_id)->where('type', 'webhook');

        $webhook()->increment('failures');

        // Só um worker para o envio (a condição está no UPDATE): o e-mail sai uma vez.
        $stopped = $webhook()->where('status', 'active')->where('failures', '>=', $max)->update([
            'status' => 'broken',
            'broken_at' => now(),
            'last_error' => "{$max} entregas seguidas falharam e o envio parou. A última: ".($delivery->error ?: 'sem resposta').' Envie um teste: se der certo, o webhook volta a receber.',
            'updated_at' => now(),
        ]);

        if ($stopped === 1 && ($channel = $webhook()->with('user')->first()) && $channel->user?->email) {
            $this->outbox->send(
                new WebhookPaused($channel->user->name, $channel->appName(), (string) $channel->webhook_host, $max, (string) ($delivery->error ?: 'sem resposta'), $channel->settingsUrl()),
                $channel->user->email,
                'webhook',
                $channel->user,
            );
        }
    }
}
