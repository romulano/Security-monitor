<?php

namespace App\Http\Requests\Alerts\Github;

/** A página do GitHub: os monitoramentos que a conta vê, o escolhido por `?monitor=` e o filtro. */
class ShowGithubRequest extends GithubRequest
{
    /** O filtro pedido em `?filtro=` (nulo: "Todos"). */
    public function filter(): ?string
    {
        $filter = $this->query('filtro');

        return is_string($filter) ? $filter : null;
    }
}
