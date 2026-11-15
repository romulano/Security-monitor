<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;

/** "Contar como nosso" na ficha do IP: o IP vem no corpo. */
class AddOwnAddressRequest extends Request
{
    public function rules(): array
    {
        return ['endereco' => ['required', 'ip']];
    }

    public function messages(): array
    {
        return ['endereco.required' => 'Informe o IP.', 'endereco.ip' => 'IP inválido.'];
    }

    public function address(): string
    {
        return (string) $this->validated('endereco');
    }
}
