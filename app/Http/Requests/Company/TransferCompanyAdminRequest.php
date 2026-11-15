<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

/** A conta que passa a administrar; a empresa e o estado dela o CompanyRoles confere. */
class TransferCompanyAdminRequest extends Request
{
    public function rules(): array
    {
        return ['member' => ['required', 'integer']];
    }

    public function messages(): array
    {
        return ['member.*' => 'Escolha a conta que vai administrar a empresa.'];
    }
}
