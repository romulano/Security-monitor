<?php

namespace App\Http\Requests\Admin\Articles;

use App\Http\Requests\Request;

class ShowCollectionsRequest extends Request
{
    public function rules(): array
    {
        return [
            'source' => ['nullable', 'string', 'max:160'],
            // sem_ia: viraram matéria sem a análise da IA (ela falhou e entrou a heurística).
            // fora_do_feed: a matéria saiu do feed público (fora de segurança).
            'status' => ['nullable', 'in:enriched,no_update,pending,failed,sem_ia,fora_do_feed'],
            'extraction' => ['nullable', 'in:full,summary,empty,manual'],
            'window' => ['nullable', 'in:1,6,24,168,720'],
        ];
    }
}
