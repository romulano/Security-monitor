<?php

namespace App\Http\Requests\Concerns;

use App\Support\QueryTokens;

/** Feed do site e da API: a busca (`?q=`) passa do teto de filtros `campo:valor` (QueryTokens::MAX_FILTERS)? */
trait LimitsSearchFilters
{
    public function tooManyFilters(): bool
    {
        return QueryTokens::filterCount($this->term()) > QueryTokens::MAX_FILTERS;
    }
}
