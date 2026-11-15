<?php

namespace App\Services\Access;

use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;
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

    /** As raízes deste canal (o item do $query filtrado à conta do canal): o Radar, o alerta, o monitoramento dela. */
    public function sourcesFor(NotificationChannel $channel, Builder $query): Builder
    {
        return $query->where('user_id', $channel->user_id);
    }

    /** O canal alcança este item? */
    public function reaches(NotificationChannel $channel, Model $item): bool
    {
        return $this->for($item)->contains('id', $channel->id);
    }

    /** As raízes do mesmo destino que esta raiz (a mesma conta), em SQL. */
    public function sameDestination(Builder $query, Model $root): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $root->getAttribute('user_id'));
    }

    /** A chave do destino, para juntar numa mensagem só e para a anti-repetição: "u:{conta}". */
    public function key(Model $item): string
    {
        return 'u:'.$this->visibility->rootOf($item)?->getAttribute('user_id');
    }
}
