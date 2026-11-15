<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

class ResetCompanyMemberTwoFactorRequest extends Request
{
    public function rules(): array
    {
        return ['member' => ['required', 'integer']];
    }

    public function messages(): array
    {
        return ['member.required' => 'Escolha a conta.'];
    }
}
