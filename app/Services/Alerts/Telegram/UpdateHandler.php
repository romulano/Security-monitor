<?php

namespace App\Services\Alerts\Telegram;

use App\Exceptions\ActionRefused;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerts\Notifier;
use App\Services\Access\Channels;
use Illuminate\Database\Eloquent\Builder;

/**
 * O que o bot faz com o que chega do Telegram. Ele só reage a três coisas:
 * o /start com o código de conexão (conecta a conversa ao cliente e manda o
 * teste), a própria saída ou volta numa conversa (bloqueado, removido do
 * grupo, desbloqueado) e a troca de ID de um grupo que virou supergrupo.
 * Na conversa privada, qualquer outra mensagem recebe a explicação; nos
 * grupos o bot fica quieto. Nada do que as pessoas escrevem é guardado.
 */
class UpdateHandler
{
    public function __construct(
        private TelegramClient $telegram,
        private ConnectionLinks $links,
        private Notifier $notifier,
        private MessageBuilder $messages,
        private Channels $channels,
    ) {}

    public function handle(array $update): void
    {
        if (is_array($update['my_chat_member'] ?? null)) {
            $this->membership($update['my_chat_member']);

            return;
        }

        $message = $update['message'] ?? null;

        if (! is_array($message) || ! isset($message['chat']['id'])) {
            return;
        }

        $chatId = (string) $message['chat']['id'];

        if (isset($message['migrate_to_chat_id'])) {
            $this->channels($chatId)->update(['telegram_chat_id' => (string) $message['migrate_to_chat_id']]);

            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $private = ($message['chat']['type'] ?? null) === 'private';

        if (preg_match('#^/(start|ajuda|help)(?:@(\w+))?(?:\s+(\S+))?#i', $text, $m)) {
            // Num grupo com mais de um bot, o comando de outro bot não é com a gente.
            if (! empty($m[2]) && strcasecmp($m[2], TelegramClient::username()) !== 0) {
                return;
            }

            if (strtolower($m[1]) === 'start' && ! empty($m[3])) {
                $this->connect($m[3], $message);

                return;
            }

            $this->reply($chatId, $this->help());

            return;
        }

        if ($private && $text !== '') {
            $this->reply($chatId, $this->help());
        }
    }

    private function connect(string $code, array $message): void
    {
        $chat = $message['chat'];
        $chatId = (string) $chat['id'];
        $type = (string) ($chat['type'] ?? '');

        if (! in_array($type, ['private', 'group', 'supergroup'], true)) {
            return;
        }

        $link = $this->links->consume($code);
        $user = $link ? User::query()->find($link['user']) : null;

        if (! $user) {
            $this->reply($chatId, 'Esse link de conexão expirou ou já foi usado. Gere outro em '
                .'<a href="'.$this->messages->esc(route('alerts.telegram')).'">Alertas → Notificadores → Telegram</a>.');

            return;
        }

        try {
            $channel = $this->channels->unpack($user, $link['target'], 'telegram');
            $previous = $channel->method === 'group' ? $channel->telegram_chat_id : null;

            $this->channels->save($user, $channel, [
                'method' => $type === 'private' ? 'private' : 'group',
                'telegram_chat_id' => $chatId,
                'telegram_chat_title' => $type === 'private' ? null : (mb_substr((string) ($chat['title'] ?? ''), 0, 120) ?: null),
                'telegram_username' => $this->name($message['from'] ?? []),
                'status' => 'active',
                'broken_at' => null,
                'last_error' => null,
            ]);
        } catch (ActionRefused $refused) {
            $this->reply($chatId, $this->messages->esc($refused->getMessage()));

            return;
        }

        if ($previous !== $chatId) {
            $this->telegram->leaveIfUnused($previous);
        }

        $this->notifier->test($channel);
    }

    /**
     * O bot saiu (bloqueado na conversa privada, removido do grupo) ou voltou.
     * Na volta, o canal que estava quebrado por isso recebe de novo.
     */
    private function membership(array $update): void
    {
        $chatId = (string) ($update['chat']['id'] ?? '');
        $status = $update['new_chat_member']['status'] ?? null;

        if ($chatId === '') {
            return;
        }

        if (in_array($status, ['left', 'kicked'], true)) {
            $private = ($update['chat']['type'] ?? null) === 'private';
            $error = TelegramResult::permanent(0, $private ? 'blocked' : 'kicked', breaksChannel: true)->humanError();

            $this->channels($chatId)->where('status', 'active')
                ->update(['status' => 'broken', 'broken_at' => now(), 'last_error' => $error]);

            return;
        }

        if (in_array($status, ['member', 'administrator'], true)) {
            $this->channels($chatId)->where('status', 'broken')
                ->update(['status' => 'active', 'broken_at' => null, 'last_error' => null]);
        }
    }

    private function channels(string $chatId): Builder
    {
        return NotificationChannel::query()->where('type', 'telegram')->where('telegram_chat_id', $chatId);
    }

    private function reply(string $chatId, string $text): void
    {
        $this->telegram->send($chatId, ['text' => $text, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]]);
    }

    private function help(): string
    {
        return 'Olá! Eu sou o bot do <b>Security Monitor</b>, inteligência de ameaças. Aviso aqui quando sai uma notícia de segurança '
            ."que afeta a stack da sua empresa, na hora ou num resumo diário.\n\n"
            .'Para receber os alertas, entre no Security Monitor, abra <a href="'.$this->messages->esc(route('alerts.telegram')).'">Alertas → Notificadores → Telegram</a> '
            .'e toque em <b>Abrir no Telegram</b>.';
    }

    /** "@usuario", ou o nome quando a conta não tem usuário. */
    private function name(array $from): ?string
    {
        $name = filled($from['username'] ?? null)
            ? '@'.$from['username']
            : trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 120) : null;
    }
}
