<?php

namespace App\Http\Requests\Admin\Alerts\Clients;

class RecompileClientRequest extends ClientAlertRequest
{
    public function authorize(): bool
    {
        return $this->profile() !== null;
    }
}
