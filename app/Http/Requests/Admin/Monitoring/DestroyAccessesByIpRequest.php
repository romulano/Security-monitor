<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;

/** "Apagar tudo deste IP", na ficha do IP: o IP vem no corpo. */
class DestroyAccessesByIpRequest extends Request
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
