<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyRole;
use App\Http\Requests\Request;
use Illuminate\Validation\Rule;

class StoreCompanyInviteRequest extends Request
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([CompanyRole::Member->value, CompanyRole::SectorManager->value])],
            // Os setores pré-escolhidos (E2a3); se são da empresa, o serviço confere.
            'sectors' => ['nullable', 'array', 'max:50'],
            'sectors.*' => ['integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Informe o e-mail de quem você quer convidar.',
            'email.email' => 'E-mail inválido.',
            'email.max' => 'O e-mail pode ter no máximo 255 caracteres.',
            'role.*' => 'Escolha o papel: membro ou responsável do setor.',
            'sectors.*' => 'Escolha setores da empresa.',
        ];
    }

    public function role(): CompanyRole
    {
        return CompanyRole::from($this->validated('role'));
    }

    /** @return list<int> */
    public function sectorIds(): array
    {
        return array_map('intval', $this->validated('sectors') ?? []);
    }
}
