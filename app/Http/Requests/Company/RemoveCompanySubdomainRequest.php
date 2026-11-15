<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;
use App\Services\Company\Visibility;

/** O subdomínio da URL é de um domínio que a conta vê (senão, 404); quem tira é conferido no CompanySubdomains. */
class RemoveCompanySubdomainRequest extends Request
{
    public function authorize(): bool
    {
        return app(Visibility::class)->sees($this->user(), $this->route('subdomain'));
    }
}
