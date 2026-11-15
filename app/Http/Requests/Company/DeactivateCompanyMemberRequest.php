<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyRole;
use App\Http\Requests\Request;

/** A conta da URL é da empresa de quem pede, ativa e não é a admin (a admin não remove a si mesma); senão, 404. */
class DeactivateCompanyMemberRequest extends Request
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member->company_id === $this->user()->companyMembership?->company_id
            && $member->role !== CompanyRole::Admin && ! $member->isDeactivated();
    }
}
