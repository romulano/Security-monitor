<?php

namespace App\Http\Requests\Admin\Alerts\Clients;

use App\Http\Requests\Request;

class ListClientsRequest extends Request
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'perfil' => ['nullable', 'in:active,draft,paused,none'],
            'canal' => ['nullable', 'in:ok,broken,none'],
        ];
    }

    /** Busca (`q`), situação do perfil (`perfil`) e do canal (`canal`). */
    public function filters(): array
    {
        return $this->validated();
    }
}
