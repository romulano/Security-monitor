<?php

namespace App\Http\Requests\Admin\Queue;

use App\Http\Requests\Request;

/** Qual lista abrir: os jobs na fila (padrão) ou os que falharam. */
class ShowQueueRequest extends Request
{
    public function rules(): array
    {
        return [
            'ver' => ['nullable', 'in:fila,falhos'],
        ];
    }
}
