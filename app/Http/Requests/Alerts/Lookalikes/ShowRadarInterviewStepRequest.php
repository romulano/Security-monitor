<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Services\Lookalike\RadarInterview;

class ShowRadarInterviewStepRequest extends RadarInterviewRequest
{
    /** Passo fora da entrevista: 404. */
    public function authorize(): bool
    {
        return parent::authorize() && $this->step() >= 1 && $this->step() <= RadarInterview::LAST_STEP;
    }
}
