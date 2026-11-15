<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;
use App\Services\Company\Visibility;

/** "Verificar agora" de um domínio da empresa: só quem o configura (a admin; o responsável, os do setor dele). O resto, 404. */
class VerifyCompanyDomainRequest extends Request
{
    public function authorize(): bool
    {
        return app(Visibility::class)->manages($this->user(), $this->route('domain'));
    }

    public function rules(): array
    {
        return [];
    }
}
