<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Request;
use App\Services\Company\Visibility;

class ResendAlertRequest extends Request
{
    /** Reenviar é configurar (R14): quem configura o alerta do `{match}`; quem só vê, ou não vê, leva 404. */
    public function authorize(): bool
    {
        return app(Visibility::class)->manages($this->user(), $this->route('match'));
    }
}
