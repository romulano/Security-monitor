<?php

namespace App\Http\Requests\Alerts;

use App\Services\Access\Visibility;

class StoreAlertFeedbackRequest extends FeedbackRequest
{
    /** Avalia quem vê o alerta (`{match}`), membro também (§4.3); quem não vê leva 404. */
    public function authorize(): bool
    {
        return app(Visibility::class)->sees($this->user(), $this->route('match'));
    }
}
