<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Request;

class ConfirmTwoFactorRequest extends Request
{
    protected $errorBag = 'two_factor';

    public function rules(): array
    {
        return [
            // O formato (6 dígitos, com ou sem espaço) e a conferência ficam no TwoFactor.
            'two_factor_code' => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'two_factor_code.required' => 'Digite o código de 6 dígitos que o app mostra.',
            'two_factor_code.max' => 'O código não confere. Digite os 6 dígitos que o app mostra agora.',
        ];
    }
}
