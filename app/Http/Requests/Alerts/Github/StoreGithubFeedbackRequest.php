<?php

namespace App\Http\Requests\Alerts\Github;

use App\Http\Requests\Alerts\FeedbackRequest;
use App\Services\Access\Visibility;

class StoreGithubFeedbackRequest extends FeedbackRequest
{
    /** Avalia o achado (`{finding}`) quem o vê (o membro do setor também vota, §4.3); o resto, 404. */
    public function authorize(): bool
    {
        return app(Visibility::class)->sees($this->user(), $this->route('finding'));
    }
}
