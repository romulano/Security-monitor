<?php

namespace App\Models\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Raiz da visibilidade (DESENHO-5.2 §4): o que tem dono próprio, com `user_id` (quem criou), `company_id` (nulo = conta
 * pessoal) e `sector_id` (nulo = sem setor). Hoje: o alerta de Notícias, o Radar e o GitHub. Quem diz quem vê é o
 * `App\Services\Company\Visibility`; o scope `visibleTo` (trait VisibleToAccount) só o chama.
 *
 * @method static Builder<static> visibleTo(User $user)
 * @method static Builder<static> manageableBy(User $user)
 */
interface VisibilityRoot {}
