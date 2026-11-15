<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyMemberRoleRequest;
use App\Models\CompanyMember;
use App\Services\Company\CompanyRoles;
use Illuminate\Http\RedirectResponse;

class UpdateCompanyMemberRoleController extends Controller
{
    public function __construct(private CompanyRoles $roles) {}

    public function __invoke(UpdateCompanyMemberRoleRequest $request, CompanyMember $member): RedirectResponse
    {
        $this->roles->change($member, $request->role(), $request->user());

        return redirect()->route('company.accounts')->with('status', "{$member->user->name} agora é {$request->role()->label()}.");
    }
}
