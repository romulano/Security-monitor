<?php

namespace App\Http\Requests\Alerts\Github;

use App\Services\Github\GithubInterview;

class ShowGithubInterviewStepRequest extends GithubInterviewRequest
{
    /** Só os passos que existem, e só para quem configura (ou cria); o resto é 404. */
    public function authorize(): bool
    {
        return $this->step() >= 1 && $this->step() <= GithubInterview::LAST_STEP && parent::authorize();
    }
}
