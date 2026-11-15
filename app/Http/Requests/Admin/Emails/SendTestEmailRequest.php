<?php

namespace App\Http\Requests\Admin\Emails;

use App\Http\Requests\Request;

/** Para quem vai o e-mail de teste do Admin → E-mails. */
class SendTestEmailRequest extends Request
{
    public function rules(): array
    {
        return [
            'to' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.required' => 'Informe para quem enviar o teste.',
            'to.email' => 'E-mail inválido.',
            'to.max' => 'O e-mail pode ter no máximo 255 caracteres.',
        ];
    }
}
