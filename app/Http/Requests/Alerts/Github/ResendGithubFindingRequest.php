<?php

namespace App\Http\Requests\Alerts\Github;

use App\Services\Access\Visibility;

class ResendGithubFindingRequest extends ShowGithubFindingRequest
{
    /** Reenviar manda pelos canais de quem criou: só quem configura o monitoramento (R14); o resto, 404. */
    public function authorize(): bool
    {
        return app(Visibility::class)->manages($this->user(), $this->route('finding'));
    }
}
