<?php

namespace App\Services\Access;

use App\Models\Contracts\VisibilityRoot;
use App\Models\Contracts\VisibleThroughRoot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * De quem é cada item e quem pode mexer nele. Na versão aberta não existe empresa nem setor: o item é da conta que o
 * criou (`user_id` na raiz), e quem o vê também o configura.
 *
 * A raiz é o que tem dono próprio (o alerta de Notícias, o Radar, o monitoramento do GitHub); o filho (o match, a
 * imitação, o achado) herda a dela.
 */
class Visibility
{
    /** A raiz de onde vem a visibilidade do item, ou nulo se ele não tem uma. */
    public function rootOf(Model $item): ?Model
    {
        return match (true) {
            $item instanceof VisibleThroughRoot => $item->visibilityRoot(),
            $item instanceof VisibilityRoot => $item,
            default => null,
        };
    }

    /** As colunas de dono que um item novo recebe. O setor existia só no plano empresarial. */
    public function stampFor(User $user, ?int $sectorId = null): array
    {
        return ['user_id' => $user->id];
    }

    /** A conta vê este item? */
    public function sees(User $user, Model $item): bool
    {
        return $this->rootOf($item)?->getAttribute('user_id') === $user->id;
    }

    /** A conta configura este item? Sem empresa, é a mesma regra de ver. */
    public function manages(User $user, Model $item): bool
    {
        return $this->sees($user, $item);
    }
}
