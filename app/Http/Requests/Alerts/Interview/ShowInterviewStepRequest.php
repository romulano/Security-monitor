<?php

namespace App\Http\Requests\Alerts\Interview;

use App\Services\Alerts\InterviewService;

class ShowInterviewStepRequest extends InterviewRequest
{
    /** Passo que não existe: 404. Alerta novo de empresa: só o passo 1, para quem pode criar. */
    public function authorize(): bool
    {
        return $this->step() >= 1 && $this->step() <= InterviewService::LAST_STEP && (! $this->startsNew() || $this->mayStartNew());
    }
}
