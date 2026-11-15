<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

/** O setor e a conta da URL são da empresa de quem pede; senão, 404. */
class RemoveCompanySectorMemberRequest extends Request
{
    public function authorize(): bool
    {
        $company = $this->user()->companyMembership?->company_id;

        return $this->route('sector')->company_id === $company && $this->route('member')->company_id === $company;
    }

    public function rules(): array
    {
        return [];
    }
}
