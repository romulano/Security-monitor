<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Os scopes `visibleTo($user)` e `manageableBy($user)` das raízes (VisibilityRoot): o item é da conta que o criou.
 *
 * Na versão aberta não existe empresa nem setor, então ver e configurar são a mesma regra. Os scopes continuam
 * existindo porque os filhos (VisibleThroughRoot) os consultam por subconsulta da raiz.
 */
trait VisibleToAccount
{
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where($this->qualifyColumn('user_id'), $user->id);
    }

    public function scopeManageableBy(Builder $query, User $user): void
    {
        $query->where($this->qualifyColumn('user_id'), $user->id);
    }
}
