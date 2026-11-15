<?php

namespace App\Http\Requests\Admin\Alerts\Clients;

class UpdateClientTechRequest extends ClientAlertRequest
{
    /** Sem alerta, 404 antes de validar. */
    public function authorize(): bool
    {
        return $this->profile() !== null;
    }

    public function rules(): array
    {
        return [
            'tech_id' => ['required', 'integer', 'exists:tech_catalog,id'],
            'action' => ['required', 'in:add,remove'],
        ];
    }

    public function techId(): int
    {
        return (int) $this->validated('tech_id');
    }

    /** `action=add` acrescenta; `remove` tira. */
    public function adding(): bool
    {
        return $this->validated('action') === 'add';
    }
}
