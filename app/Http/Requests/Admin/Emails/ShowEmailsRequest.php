<?php

namespace App\Http\Requests\Admin\Emails;

use App\Http\Requests\Request;

/** ?erros=1 mostra só o que não foi entregue (adiado, devolvido, falhou). */
class ShowEmailsRequest extends Request
{
    public function rules(): array
    {
        return [
            'erros' => ['nullable', 'boolean'],
        ];
    }
}
