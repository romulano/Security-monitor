<?php

namespace App\Models\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Filho de uma raiz da visibilidade (o match do alerta, a imitação do Radar, o achado do GitHub...): não tem empresa
 * nem setor próprios, vale o da raiz. O scope `visibleTo` do filho é uma subconsulta da raiz.
 *
 * @method static Builder<static> visibleTo(User $user)
 */
interface VisibleThroughRoot
{
    /** A raiz de onde vem a visibilidade; nula se o item não tem raiz visível (então ninguém de empresa o vê). */
    public function visibilityRoot(): ?Model;
}
