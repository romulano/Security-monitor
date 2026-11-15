<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;

/** Admin → Acessos, aba Ajustes: não lê nada da requisição (a página só mostra a chave e a lista de nossos). */
class ShowAccessSettingsRequest extends Request
{
    public function rules(): array
    {
        return [];
    }
}
