<?php

namespace App\Http\Requests\Site;

use App\Http\Requests\Concerns\LimitsSearchFilters;
use App\Http\Requests\Request;
use App\Support\ReportLanguage;

class ShowFeedRequest extends Request
{
    use LimitsSearchFilters;

    /** A busca (`?q=`), com os filtros em forma de token; `q[]=` (lista) vale como busca vazia. */
    public function term(): string
    {
        $term = $this->query('q', '');

        return is_string($term) ? trim($term) : '';
    }

    public function page(): int
    {
        return max(1, (int) $this->query('page', 1));
    }

    public function language(): string
    {
        return ReportLanguage::fromRequest($this);
    }
}
