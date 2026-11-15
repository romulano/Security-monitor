<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;
use App\Services\Company\CompanyInvites;

/** O convite só abre enquanto vale (CompanyInvites::findPending); senão, 404 com a página amigável. */
class ShowCompanyInviteRequest extends Request
{
    public function authorize(CompanyInvites $invites): bool
    {
        return $invites->findPending((string) $this->route('token')) !== null;
    }
}
