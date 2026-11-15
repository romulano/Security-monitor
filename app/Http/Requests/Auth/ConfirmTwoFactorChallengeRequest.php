<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;

class ConfirmTwoFactorChallengeRequest extends Request
{
    public function rules(): array
    {
        // O formato e a conferência ficam no TwoFactorChallenge (o código do app ou um de recuperação).
        return ['two_factor_code' => ['required', 'string', 'max:20']];
    }

    public function messages(): array
    {
        return [
            'two_factor_code.required' => 'Digite o código do app ou um código de recuperação.',
            'two_factor_code.max' => 'O código não confere.',
        ];
    }
}
