<?php

namespace App\Services\Access;

use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Para onde vai o aviso de um item: os canais da conta que o criou, na ordem de sempre (Telegram, Slack, Discord e
 * depois os webhooks). Sem empresa, não há canal de equipe nem de setor.
 */
class Destinations
{
    public function __construct(private Visibility $visibility) {}

    /** @return Collection<int, NotificationChannel> */
    public function for(Model $item): Collection
    {
        $root = $this->visibility->rootOf($item);

        return $root?->user?->notificationChannels ?? new Collection;
    }

    /** A chave do destino, para juntar numa mensagem só e para a anti-repetição: "u:{conta}". */
    public function key(Model $item): string
    {
        return 'u:'.$this->visibility->rootOf($item)?->getAttribute('user_id');
    }
}
