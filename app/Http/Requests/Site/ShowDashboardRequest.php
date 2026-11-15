<?php

namespace App\Http\Requests\Site;

use App\Http\Requests\Concerns\PicksDashboardWindow;
use App\Http\Requests\Request;

class ShowDashboardRequest extends Request
{
    use PicksDashboardWindow;

    /** A busca do cabeçalho, para continuar preenchida. */
    public function term(): string
    {
        return trim((string) $this->query('q', ''));
    }
}
