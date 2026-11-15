<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

/** O setor da URL é da empresa de quem pede (senão, 404); a conta escolhida é conferida pelo serviço. */
class AddCompanySectorMemberRequest extends Request
{
    public function authorize(): bool
    {
        return $this->route('sector')->company_id === $this->user()->companyMembership?->company_id;
    }

    public function rules(): array
    {
        return ['member' => ['required', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['member.*' => 'Escolha uma conta da empresa.'];
    }

    public function memberId(): int
    {
        return (int) $this->validated('member');
    }
}
