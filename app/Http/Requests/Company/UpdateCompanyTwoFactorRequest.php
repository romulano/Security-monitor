<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

class UpdateCompanyTwoFactorRequest extends Request
{
    public function rules(): array
    {
        return ['required' => ['required', 'boolean']];
    }
}
