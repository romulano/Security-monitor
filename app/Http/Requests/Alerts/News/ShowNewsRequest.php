<?php

namespace App\Http\Requests\Alerts\News;

use App\Http\Requests\Request;
use App\Services\Alerts\AlertPanel;

class ShowNewsRequest extends Request
{
    /** `?filtro=` das notícias recebidas; o que não for um dos filtros vale "todos". */
    public function filter(): string
    {
        $filter = $this->query('filtro');

        return array_key_exists($filter, AlertPanel::FILTERS) ? $filter : 'todos';
    }

    /** `?alerta=`: o histórico de um alerta só (0 = todos). */
    public function alertId(): int
    {
        return $this->integer('alerta');
    }
}
