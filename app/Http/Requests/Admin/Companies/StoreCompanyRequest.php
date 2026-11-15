<?php

namespace App\Http\Requests\Admin\Companies;

use App\Http\Requests\Request;

class StoreCompanyRequest extends Request
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:255'],
            // `filter`: a mesma conferência do Companies::create (sem ela, um e-mail com acento passava aqui e dava 500 lá).
            'admin_email' => ['required', 'email:filter', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da empresa.',
            'name.max' => 'O nome da empresa pode ter no máximo 120 caracteres.',
            'admin_name.required' => 'Informe o nome da admin.',
            'admin_email.required' => 'Informe o e-mail da admin.',
            'admin_email.email' => 'E-mail da admin inválido.',
        ];
    }
}
