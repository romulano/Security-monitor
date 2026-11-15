<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Company\Visibility;
use Illuminate\Database\Eloquent\Builder;

/** Os scopes `visibleTo($user)` e `manageableBy($user)` das raízes (VisibilityRoot): a regra é toda do serviço Visibility. */
trait VisibleToAccount
{
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        app(Visibility::class)->constrain($query, $user);
    }

    /** O que a conta configura (R14): a admin, tudo da empresa; o responsável, os setores dele; a pessoal, o dela. */
    public function scopeManageableBy(Builder $query, User $user): void
    {
        app(Visibility::class)->constrainManaged($query, $user);
    }
}
