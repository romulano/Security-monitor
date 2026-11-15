<?php

namespace App\Http\Requests\Alerts\Lookalikes;

class ToggleLookalikesPauseRequest extends RadarPageRequest
{
    /** Radar ainda na entrevista (ou nenhum), ou que a conta não configura: 404. */
    public function authorize(): bool
    {
        return parent::authorize() && $this->watch()?->isCompleted() && $this->managesWatch();
    }
}
