<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Request;
use App\Services\Alerts\AlertInbox;

class ShowAlertsRequest extends Request
{
    /**
     * `?fonte=` da lista (notícias, Radar ou GitHub); o resto vale "todas". Os
     * links antigos punham a fonte em `?filtro=` e continuam valendo.
     */
    public function source(): string
    {
        $source = $this->query('fonte');

        if (! is_string($source) && in_array($this->query('filtro'), ['noticias', 'radar', 'github'], true)) {
            $source = $this->query('filtro');
        }

        return is_string($source) && array_key_exists($source, AlertInbox::SOURCES) ? $source : 'todas';
    }

    /** `?filtro=` da lista (na hora, só no resumo, pendentes, relevantes…); o resto vale "todos". */
    public function filter(): string
    {
        $filter = $this->query('filtro');

        return is_string($filter) && array_key_exists($filter, AlertInbox::FILTERS) ? $filter : 'todos';
    }

    /** `?page=` da lista. */
    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }
}
