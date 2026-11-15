<?php

namespace App\Http\Requests\Company;

/** A senha, como no cadastro (mínimo de 8, repetida). */
class CompleteCompanyEntryRequest extends ShowCompanyEntryRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Escolha uma senha.',
            'password.min' => 'A senha precisa ter pelo menos 8 caracteres.',
            'password.max' => 'A senha pode ter no máximo 255 caracteres.',
            'password.confirmed' => 'As duas senhas não são iguais.',
        ];
    }
}
