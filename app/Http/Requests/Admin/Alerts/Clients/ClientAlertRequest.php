<?php

namespace App\Http\Requests\Admin\Alerts\Clients;

use App\Http\Requests\Request;
use App\Models\AlertProfile;

/**
 * Telas do admin sobre um cliente (`{user}` da URL) que miram um dos alertas
 * dele. O controller declara `User $user` para o `{user}` chegar aqui já como
 * modelo.
 */
abstract class ClientAlertRequest extends Request
{
    private ?AlertProfile $profile = null;

    /** O alerta de `?alerta=` (404 se não for do cliente da URL) ou, sem ele, o primeiro. */
    public function profile(): ?AlertProfile
    {
        // O admin do Security Monitor olha os alertas que o cliente da URL criou (não passa pela visibilidade de empresa).
        $owner = $this->route('user');

        return $this->profile ??= $this->filled('alerta')
            ? $owner->alertProfiles()->findOrFail($this->integer('alerta'))
            : $owner->alertProfile;
    }
}
