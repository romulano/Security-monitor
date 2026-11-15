<?php

namespace App\Http\Requests\Alerts\Lookalikes;

class DestroyRadarRequest extends RadarPageRequest
{
    /** Sem Radar não há o que excluir; quem não configura este Radar também leva 404. */
    public function authorize(): bool
    {
        return parent::authorize() && $this->managesWatch();
    }
}
