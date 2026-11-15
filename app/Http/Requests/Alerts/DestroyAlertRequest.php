<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Concerns\TargetsAlertProfile;
use App\Http\Requests\Request;
use App\Models\AlertProfile;

class DestroyAlertRequest extends Request
{
    use TargetsAlertProfile;

    /** O alerta de `?alerta=` (404 se a conta não o configura) ou, sem ele, o primeiro que ela configura (nulo se não há). */
    public function profile(): ?AlertProfile
    {
        return $this->alertProfileOf($this->user());
    }
}
