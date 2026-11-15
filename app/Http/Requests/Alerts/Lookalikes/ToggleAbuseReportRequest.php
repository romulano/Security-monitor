<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Services\Lookalike\AbuseKit;

class ToggleAbuseReportRequest extends OwnedLookalikeRequest
{
    /** Do dono, e só pelos caminhos que o kit conhece. */
    public function authorize(): bool
    {
        return parent::authorize() && array_key_exists($this->route('canal'), AbuseKit::CHANNELS);
    }
}
