<?php

namespace App\Http\Requests\Alerts\Github;

use App\Http\Requests\Request;
use App\Services\Company\Visibility;

class ShowGithubFindingRequest extends Request
{
    /** O achado (`{finding}`) só para quem vê o monitoramento (o dono; na empresa, pelo setor); o resto, 404. */
    public function authorize(): bool
    {
        return app(Visibility::class)->sees($this->user(), $this->route('finding'));
    }

    public function rules(): array
    {
        return [];
    }
}
