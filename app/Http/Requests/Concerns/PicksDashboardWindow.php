<?php

namespace App\Http\Requests\Concerns;

/** Dashboard do site e da API: a janela vem em `?window=` (dias). */
trait PicksDashboardWindow
{
    /** 30, 90, 180 ou 365 dias; qualquer outro valor vale 90. */
    public function window(): int
    {
        $window = (int) $this->query('window', 90);

        return in_array($window, [30, 90, 180, 365], true) ? $window : 90;
    }
}
