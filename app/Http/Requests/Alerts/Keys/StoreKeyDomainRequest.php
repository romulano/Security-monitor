<?php

namespace App\Http\Requests\Alerts\Keys;

use App\Http\Requests\Request;

class StoreKeyDomainRequest extends Request
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['dominio' => ['required', 'string', 'max:253']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['dominio.required' => 'Informe o domínio.', 'dominio.max' => 'Domínio longo demais.'];
    }
}
