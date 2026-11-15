<?php

namespace App\Http\Requests\Admin\Sources;

use App\Http\Requests\Request;

class ListSourcesRequest extends Request
{
    /** Busca por nome ou host (`?s=`). */
    public function search(): ?string
    {
        $search = $this->query('s');

        return is_string($search) && $search !== '' ? $search : null;
    }
}
