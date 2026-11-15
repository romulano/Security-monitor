<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

/** O convite da URL é da empresa de quem pede e está aberto; senão, 404 (nada de convite de outra empresa). */
class ResendCompanyInviteRequest extends Request
{
    public function authorize(): bool
    {
        $invite = $this->route('invite');

        return $invite->company_id === $this->user()->companyMembership?->company_id
            && $invite->accepted_at === null && $invite->revoked_at === null;
    }
}
