<?php

namespace App\Models\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Raiz da visibilidade: o que tem dono próprio, com `user_id` (quem criou). Hoje: o alerta de Notícias, o Radar e o
 * GitHub. O scope `visibleTo` vem da trait VisibleToAccount.
 *
 * @method static Builder<static> visibleTo(User $user)
 * @method static Builder<static> manageableBy(User $user)
 */
interface VisibilityRoot {}
