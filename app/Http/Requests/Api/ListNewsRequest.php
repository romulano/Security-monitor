<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\LimitsSearchFilters;
use App\Http\Requests\Request;

class ListNewsRequest extends Request
{
    use LimitsSearchFilters;

    /** `q[]=` (lista) vale como busca vazia. */
    public function term(): string
    {
        $term = $this->query('q', '');

        return is_string($term) ? $term : '';
    }

    public function page(): int
    {
        return (int) $this->query('page', 1);
    }
}
