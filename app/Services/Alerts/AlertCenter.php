<?php

namespace App\Services\Alerts;

use App\Models\AlertProfile;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerts\Webhook\WebhookConnector;

/**
 * /alertas/notificadores: os apps (Slack, Discord, Telegram, webhook), o que cada
 * app conectado recebe (notícias, Radar de domínios, GitHub) e a pausa do envio,
 * que segura tudo sem desconectar o app. As escolhas valem para o envio na
 * hora, os segurados, o reenvio e o resumo.
 */
class AlertCenter
{
    public function page(User $user): array
    {
        // Slack, Discord, Telegram e os webhooks (a ordem de NotificationChannel::APPS).
        $channels = $user->notificationChannels
            ->sortBy(fn (NotificationChannel $channel) => array_search($channel->type, array_keys(NotificationChannel::APPS), true))
            ->values();
        // Os de alerta e, com os testes liberados, o aviso deles.
        $types = NotificationChannel::typesFor($user);
        $webhooks = $channels->where('type', 'webhook')->count();

        return [
            // Os apps a oferecer em "Conectar outro app": os que faltam e o webhook, até o limite (a conta pode ter vários).
            'missing' => collect(array_keys(NotificationChannel::APPS))
                ->filter(fn (string $type) => $type === 'webhook' ? $webhooks < WebhookConnector::max() : ! $channels->contains('type', $type))
                ->values(),
            'webhooks' => $webhooks,
            'channels' => $channels,
            'types' => $types,
            // Quem recebe cada tipo agora: conectado, sem pausa e com o tipo ligado.
            'receivers' => collect($types)
                ->map(fn ($label, string $type) => $channels->filter(fn (NotificationChannel $channel) => $channel->receives($type))->values()),
            // O que manda para estes canais: o que a conta criou e ainda vê (até a E5b, o envio vai para quem criou).
            'sources' => [
                'news' => AlertProfile::query()->visibleTo($user)->where('user_id', $user->id)->where('status', 'active')->whereNotNull('completed_at')->exists(),
                'radar' => LookalikeWatch::query()->visibleTo($user)->where('user_id', $user->id)->where('status', 'active')->exists(),
                'github' => GithubMonitor::query()->visibleTo($user)->where('user_id', $user->id)->where('status', 'active')->exists(),
                NotificationChannel::TESTS => $user->pentestDomains()->exists(),
            ],
        ];
    }
}
