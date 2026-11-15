<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Request;

class ConfirmReauthTwoFactorRequest extends Request
{
    // O aviso aparece na caixa "Falta o código do app".
    protected $errorBag = 'reauth';

    public function rules(): array
    {
        return ['reauth_two_factor_code' => ['required', 'string', 'max:20']];
    }

    public function messages(): array
    {
        return [
            'reauth_two_factor_code.required' => 'Digite o código do app autenticador.',
            'reauth_two_factor_code.max' => 'O código do app não confere.',
        ];
    }
}
