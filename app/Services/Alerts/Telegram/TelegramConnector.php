<?php

namespace App\Services\Alerts\Telegram;

use App\Exceptions\ActionRefused;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerts\ChannelSettings;
use App\Services\Access\Channels;
use Illuminate\Contracts\Session\Session;

/**
 * /alertas/telegram: conectar e desconectar. O cliente gera um link de uso
 * único que abre o Telegram com o /start do código, numa conversa privada com
 * o bot ou num grupo; quem conecta é o bot, ao receber o /start
 * (UpdateHandler). Enquanto isso, a página pergunta se o link já foi usado.
 */
class TelegramConnector
{
    private const LINK = 'telegram_link';

    public function __construct(
        private TelegramClient $telegram,
        private ConnectionLinks $links,
        private ChannelSettings $settings,
        private Channels $channels,
    ) {}

    public function page(User $user, ?NotificationChannel $channel, Session $session, bool $changing): array
    {
        return $this->settings->page($user, $channel, $changing) + [
            'available' => TelegramClient::configured(),
            'bot' => TelegramClient::username(),
            'link' => $this->pendingLink($session),
        ];
    }

    /**
     * O link desta sessão saiu da espera: verdadeiro se o bot conectou a
     * conversa, falso se o tempo acabou (e o link sai da sessão). Nulo quando
     * não há link ou ele ainda espera o /start.
     */
    public function finishedLink(?NotificationChannel $channel, Session $session): ?bool
    {
        $started = $session->get(self::LINK);

        if (! is_array($started) || $this->pendingLink($session)) {
            return null;
        }

        $session->forget(self::LINK);

        return $channel && $channel->updated_at->timestamp >= (int) ($started['at'] ?? PHP_INT_MAX);
    }

    /**
     * "Numa conversa privada" ou "Num grupo" (`$group`): gera o link que o botão da página abre. Na empresa (E5a), só
     * num grupo (P1), e quem pode conectar no escopo é conferido já aqui e de novo quando o grupo responde.
     */
    public function startLink(User $user, Session $session, bool $group, ?NotificationChannel $existing = null): void
    {
        $target = Channels::pack($existing);

        $this->channels->target($user, 'telegram', $existing);

        $session->put(self::LINK, [
            'code' => $this->links->create($user->id, $target),
            'group' => $group,
            'at' => now()->timestamp,
        ]);
    }

    /** Desiste do link: ele deixa de valer na hora. */
    public function cancelLink(Session $session): void
    {
        if ($link = $session->pull(self::LINK)) {
            $this->links->consume((string) ($link['code'] ?? ''));
        }
    }

    /** A página pergunta enquanto espera o /start: quando o link é usado ou expira, ela recarrega. */
    public function waiting(Session $session): bool
    {
        return $this->pendingLink($session) !== null;
    }

    /** Desconecta; no canal da empresa, `$by` vai à Atividade. */
    public function disconnect(?NotificationChannel $channel, ?User $by = null): void
    {
        $chatId = $channel?->method === 'group' ? $channel->telegram_chat_id : null;

        $channel?->delete();

        if ($channel !== null && $by !== null) {
        }

        $this->telegram->leaveIfUnused($chatId);
    }

    /** O link gerado nesta sessão que ainda espera o /start, com a URL que abre o Telegram. */
    private function pendingLink(Session $session): ?array
    {
        $link = $session->get(self::LINK);

        if (! is_array($link) || ! $this->links->pending((string) ($link['code'] ?? ''))) {
            return null;
        }

        return $link + ['url' => TelegramClient::deepLink($link['code'], (bool) $link['group'])];
    }
}
