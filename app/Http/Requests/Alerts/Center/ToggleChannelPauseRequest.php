<?php

namespace App\Http\Requests\Alerts\Center;

use App\Http\Requests\Request;
use App\Services\Company\Visibility;

class ToggleChannelPauseRequest extends Request
{
    /**
     * Só quem configura o canal (`{channel}`) pausa: o dono do pessoal; no da empresa, quem administra (gerais) ou quem
     * responde pelo setor dele — nunca quem só o conectou (E5a). De outro cliente, 404.
     */
    public function authorize(): bool
    {
        return app(Visibility::class)->manages($this->user(), $this->route('channel'));
    }
}
