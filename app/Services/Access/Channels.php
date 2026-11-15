<?php

namespace App\Services\Access;

use App\Exceptions\ActionRefused;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * O canal de aviso que um conector vai gravar: o da conta, ou um que já existe e é dela. Sem empresa não há canal de
 * equipe nem de setor, então não há escopo a escolher nem limite a conferir.
 */
class Channels
{
    public function __construct(private Visibility $visibility) {}

    /** O canal que o conector vai gravar: o que já existe (se for da conta e do mesmo app) ou o da conta. */
    public function target(User $user, string $type, ?NotificationChannel $existing = null): NotificationChannel
    {
        if ($existing !== null) {
            return $existing->type === $type && $existing->user_id === $user->id
                ? $existing : throw new ActionRefused('Este canal não está entre os que você configura.');
        }

        return NotificationChannel::forUser($user->id, $type);
    }

    /** Grava o que o conector montou. */
    public function save(User $by, NotificationChannel $channel, array $attributes): NotificationChannel
    {
        return DB::transaction(function () use ($channel, $attributes) {
            $channel->forceFill($attributes)->save();

            return $channel;
        });
    }

    /**
     * O alvo de um conector que atravessa a volta do app (o OAuth na sessão, o código do Telegram no cache): o canal
     * existente, ou vazio para o canal da conta. Só o id: quem pode é conferido de novo na volta (target).
     *
     * @return array{channel?: int}
     */
    public static function pack(?NotificationChannel $existing = null): array
    {
        return $existing !== null ? ['channel' => $existing->id] : [];
    }

    /** O alvo guardado de volta. */
    public function unpack(User $user, mixed $packed, string $type): NotificationChannel
    {
        $packed = is_array($packed) ? $packed : [];

        return isset($packed['channel'])
            ? $this->target($user, $type, existing: NotificationChannel::query()->find((int) $packed['channel'])
                ?? throw new ActionRefused('O canal foi removido enquanto você conectava. Tente de novo.'))
            : $this->target($user, $type);
    }

    /** Conversa de uma pessoa: Telegram privado (ou com o ID positivo de uma pessoa), DM do Discord. */
    public static function isPrivate(NotificationChannel $channel): bool
    {
        return ($channel->type === 'telegram' && ($channel->method === 'private' || ($channel->telegram_chat_id !== null && ! str_starts_with((string) $channel->telegram_chat_id, '-'))))
            || ($channel->type === 'discord' && $channel->method === 'dm');
    }
}
