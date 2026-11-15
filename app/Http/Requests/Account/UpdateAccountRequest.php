<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Request;
use App\Rules\EmailNotTaken;

class UpdateAccountRequest extends Request
{
    // Bags separadas: a página tem dois formulários e o erro de um não pode
    // aparecer no outro.
    protected $errorBag = 'profile';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', new EmailNotTaken(ignore: $this->user()->id)],
            // Só para trocar o e-mail (ver AccountEditor): trocar só o nome não pede a senha.
            'current_password' => ['nullable', 'string', 'max:255'],
            // Com a 2FA, o código do app também (a conferência fica na Reauthentication).
            'reauth_two_factor_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome.',
            'name.max' => 'O nome pode ter no máximo 120 caracteres.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'E-mail inválido.',
            'email.max' => 'O e-mail pode ter no máximo 255 caracteres.',
            'current_password.max' => 'A senha não confere.',
        ];
    }

    /** @return array{name: string, email: string} */
    public function profile(): array
    {
        return $this->safe()->only(['name', 'email']);
    }

    public function currentPassword(): ?string
    {
        return $this->validated('current_password');
    }

    public function twoFactorCode(): ?string
    {
        return $this->validated('reauth_two_factor_code');
    }
}
