<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Models\LookalikeDomain;

class ShowCaptureShotRequest extends OwnedLookalikeRequest
{
    /** `?baixar=1`: vem como anexo, para a denúncia. */
    public function download(): bool
    {
        return $this->boolean('baixar');
    }

    /** O domínio da visita (`{capture}`). */
    protected function targetLookalike(): ?LookalikeDomain
    {
        return $this->route('capture')->lookalike;
    }
}
