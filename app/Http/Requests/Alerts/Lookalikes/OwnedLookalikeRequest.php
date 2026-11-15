<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Http\Requests\Request;
use App\Models\LookalikeDomain;
use App\Services\Company\Visibility;

/**
 * Telas de um domínio do Radar (`{lookalike}`), o voto e a denúncia: quem vê o Radar (o dono; na empresa, a admin e
 * os setores dele, R4). Para o resto, 404.
 */
abstract class OwnedLookalikeRequest extends Request
{
    public function authorize(): bool
    {
        $lookalike = $this->targetLookalike();

        return $lookalike !== null && app(Visibility::class)->sees($this->user(), $lookalike);
    }

    /** O domínio do Radar que a rota mira. */
    protected function targetLookalike(): ?LookalikeDomain
    {
        return $this->route('lookalike');
    }
}
