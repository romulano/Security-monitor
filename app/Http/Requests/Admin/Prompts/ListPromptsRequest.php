<?php

namespace App\Http\Requests\Admin\Prompts;

use App\Http\Requests\Request;
use App\Services\Prompts\PromptCatalog;

class ListPromptsRequest extends Request
{
    /** Grupo do filtro (?grupo=fusao); grupo desconhecido mostra todos. */
    public function group(): ?string
    {
        $group = $this->query('grupo');

        return is_string($group) && isset(PromptCatalog::GROUPS[$group]) ? $group : null;
    }
}
