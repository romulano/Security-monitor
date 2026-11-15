<?php

namespace App\Http\Requests\Alerts\Lookalikes;

class ShowLookalikesRequest extends RadarPageRequest
{
    /** O filtro pedido em `?filtro=` (nulo: nenhum, e a página escolhe pelo que achou). */
    public function filter(): ?string
    {
        $filter = $this->query('filtro');

        return $this->filled('filtro') && is_string($filter) ? $filter : null;
    }
}
