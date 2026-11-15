<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Services\Lookalike\RadarInterview;

class ActivateRadarRequest extends RadarInterviewRequest
{
    /** Só depois dos passos 1 e 2: 404 antes disso. */
    public function authorize(): bool
    {
        return parent::authorize() && $this->watch()?->step_reached >= RadarInterview::LAST_STEP;
    }

    public function rules(): array
    {
        return ['consent' => ['nullable', 'boolean']];
    }

    /** Autorizou os prints do site (opcional). */
    public function consent(): bool
    {
        return $this->boolean('consent');
    }
}
