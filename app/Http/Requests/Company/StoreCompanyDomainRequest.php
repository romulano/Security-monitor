<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

/** Cadastrar um domínio na empresa: o nome e o setor (vazio: sem setor, só a admin). O serviço confere o setor e o limite. */
class StoreCompanyDomainRequest extends Request
{
    public function rules(): array
    {
        return [
            'dominio' => ['required', 'string', 'max:255'],
            'setor' => ['nullable', 'regex:/^[1-9]\d{0,17}$/'],
        ];
    }

    public function messages(): array
    {
        return ['dominio.*' => 'Informe o domínio, como empresa.com.br.', 'setor.*' => 'Escolha um setor da lista.'];
    }

    public function sectorId(): ?int
    {
        return $this->validated('setor') === null ? null : (int) $this->validated('setor');
    }
}
