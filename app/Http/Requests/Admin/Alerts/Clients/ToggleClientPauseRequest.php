<?php

namespace App\Http\Requests\Admin\Alerts\Clients;

class ToggleClientPauseRequest extends ClientAlertRequest
{
    /** Só pausa ou retoma alerta com a entrevista concluída. */
    public function authorize(): bool
    {
        return (bool) $this->profile()?->completed_at;
    }
}
