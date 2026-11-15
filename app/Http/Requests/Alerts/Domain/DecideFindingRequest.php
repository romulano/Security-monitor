<?php

namespace App\Http\Requests\Alerts\Domain;

use App\Http\Requests\Request;
use App\Services\Company\Visibility;

class DecideFindingRequest extends Request
{
    /** Achado (`{finding}`) do domínio de um alerta que a conta configura, e ligado ao catálogo; senão, 404. */
    public function authorize(): bool
    {
        $finding = $this->route('finding');

        return $finding->domain?->profile_id !== null && $finding->tech_id && app(Visibility::class)->manages($this->user(), $finding);
    }

    /** `{acao}`: "aceitar" põe de volta no perfil; "ignorar" tira. */
    public function accepting(): bool
    {
        return $this->route('acao') === 'aceitar';
    }
}
