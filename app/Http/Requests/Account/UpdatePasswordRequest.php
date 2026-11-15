<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Request;

class UpdatePasswordRequest extends Request
{
    protected $errorBag = 'password';

    public function rules(): array
    {
        return [
            // A conferência fica no AccountEditor, com o limite de tentativas da reautenticação.
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            // Com a 2FA, o código do app também (a conferência fica na Reauthentication).
            'reauth_two_factor_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function twoFactorCode(): ?string
    {
        return $this->validated('reauth_two_factor_code');
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Informe a senha atual.',
            'current_password.max' => 'A senha não confere.',
            'password.required' => 'Informe a nova senha.',
            'password.min' => 'A nova senha precisa ter pelo menos 8 caracteres.',
            'password.max' => 'A nova senha pode ter no máximo 255 caracteres.',
            'password.confirmed' => 'A confirmação não bate com a nova senha.',
        ];
    }
}
