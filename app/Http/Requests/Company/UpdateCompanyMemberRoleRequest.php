<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyRole;
use App\Http\Requests\Request;
use Illuminate\Validation\Rule;

/** A conta da URL é da empresa de quem pede, não é a admin e está ativa; senão, 404. */
class UpdateCompanyMemberRoleRequest extends Request
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member->company_id === $this->user()->companyMembership?->company_id
            && $member->role !== CompanyRole::Admin && ! $member->isDeactivated();
    }

    public function rules(): array
    {
        return ['role' => ['required', Rule::in([CompanyRole::Member->value, CompanyRole::SectorManager->value])]];
    }

    public function messages(): array
    {
        return ['role.*' => 'Escolha o papel: membro ou responsável do setor.'];
    }

    public function role(): CompanyRole
    {
        return CompanyRole::from($this->validated('role'));
    }
}
