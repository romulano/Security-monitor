<?php

namespace App\Http\Requests\Alerts\Github;

class ToggleGithubPauseRequest extends GithubRequest
{
    /** Só quem configura este monitoramento (a admin; quem responde pelo setor; a conta pessoal, o dela); o resto, 404. */
    public function authorize(): bool
    {
        return parent::authorize() && $this->managesMonitor();
    }
}
