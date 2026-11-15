<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

/** A conta da URL é da empresa de quem pede e está desativada; senão, 404. */
class ReactivateCompanyMemberRequest extends Request
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member->company_id === $this->user()->companyMembership?->company_id && $member->isDeactivated();
    }
}
