<?php

namespace App\Http\Requests\Admin\Monitoring;

/** Admin → Acessos, aba Origens: os mesmos filtros (e mensagens) da Visão geral, e o "ver mais" dos quadros da aba. */
class ShowAccessOriginsRequest extends ShowAccessesRequest
{
    public function rules(): array
    {
        return self::filterRules() + ['mais' => ['nullable', 'in:sites,entradas,cadastro']];
    }
}
