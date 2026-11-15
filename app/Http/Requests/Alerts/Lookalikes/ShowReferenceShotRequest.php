<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Http\Requests\Request;
use App\Services\Company\Visibility;

class ShowReferenceShotRequest extends Request
{
    /** Os prints do próprio site só para quem vê o Radar (o dono; na empresa, pelo setor); o resto leva 404. */
    public function authorize(): bool
    {
        return app(Visibility::class)->sees($this->user(), $this->route('watch'));
    }

    /** `?baixar=1`: vem como anexo. */
    public function download(): bool
    {
        return $this->boolean('baixar');
    }
}
