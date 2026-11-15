<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Request;
use App\Models\AlertProfile;

class StoreAlertRequest extends Request
{
    public function rules(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [];
    }

    /**
     * `copiar`: um alerta que a conta configura (404 se não), cujas respostas o novo herda. Configurar, não só ver: o
     */
    public function copyFrom(): ?AlertProfile
    {
        return $this->filled('copiar') ? AlertProfile::query()->manageableBy($this->user())->findOrFail($this->integer('copiar')) : null;
    }

}
