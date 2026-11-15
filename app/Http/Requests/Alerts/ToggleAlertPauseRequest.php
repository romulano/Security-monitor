<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Concerns\TargetsAlertProfile;
use App\Http\Requests\Request;
use App\Models\AlertProfile;

class ToggleAlertPauseRequest extends Request
{
    use TargetsAlertProfile;

    private ?AlertProfile $profile = null;

    /** Só pausa (ou retoma) um alerta que já foi ativado; rascunho é 404. */
    public function authorize(): bool
    {
        return (bool) $this->profile()?->completed_at;
    }

    /** O alerta de `?alerta=` (404 se a conta não o configura) ou, sem ele, o primeiro que ela configura. */
    public function profile(): ?AlertProfile
    {
        return $this->profile ??= $this->alertProfileOf($this->user());
    }
}
