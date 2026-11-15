<?php

namespace App\Http\Requests\Concerns;

use App\Models\AlertProfile;
use App\Models\User;

/**
 * Telas que mexem num alerta do cliente: `?alerta=ID` diz qual; sem ele, o primeiro (só na conta pessoal). Mexer é configurar (R14): só o
 * que a conta configura (a pessoal, o dela; a admin, os da empresa; o responsável, os dos setores dele). O que ela só
 * vê, ou não vê, dá 404.
 */
trait TargetsAlertProfile
{
    /** O alerta de `?alerta=` (404 se a conta não o configura) ou, sem ele, o primeiro que ela configura. */
    protected function alertProfileOf(User $user): ?AlertProfile
    {
        $query = AlertProfile::query()->manageableBy($user)->oldest('id');

        if ($this->filled('alerta')) {
            return $query->findOrFail($this->integer('alerta'));
        }

        // Na empresa, sempre pelo `?alerta=`: cada conta configura um conjunto diferente, e "o primeiro" agiria num
        // alerta que a tela não mostrou (DESENHO-5.2 §4.3; U1 do Red Team). Sem ele, nada (as telas dão 404).
        return $user->isCompanyAccount() ? null : $query->first();
    }
}
