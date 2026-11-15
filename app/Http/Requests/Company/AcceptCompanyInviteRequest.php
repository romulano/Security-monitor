<?php

namespace App\Http\Requests\Company;

/** O nome e a senha da conta nova, como no cadastro; o e-mail é o do convite. */
class AcceptCompanyInviteRequest extends ShowCompanyInviteRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o seu nome.',
            'name.max' => 'O nome pode ter no máximo 120 caracteres.',
            'password.required' => 'Escolha uma senha.',
            'password.min' => 'A senha precisa ter pelo menos 8 caracteres.',
            'password.max' => 'A senha pode ter no máximo 255 caracteres.',
            'password.confirmed' => 'As duas senhas não são iguais.',
        ];
    }
}
