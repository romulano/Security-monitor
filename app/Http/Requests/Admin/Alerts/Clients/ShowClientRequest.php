<?php

namespace App\Http\Requests\Admin\Alerts\Clients;

class ShowClientRequest extends ClientAlertRequest
{
    /** `?previa=1`: roda a prévia de 30 dias do alerta. */
    public function preview(): bool
    {
        return $this->boolean('previa');
    }
}
