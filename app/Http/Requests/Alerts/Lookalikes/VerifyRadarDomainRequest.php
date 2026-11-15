<?php

namespace App\Http\Requests\Alerts\Lookalikes;

class VerifyRadarDomainRequest extends RadarInterviewRequest
{
    /** Sem Radar não há domínio para conferir: 404. */
    public function authorize(): bool
    {
        return parent::authorize() && $this->watch() !== null;
    }
}
