<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ReactivateCompanyMemberRequest;
use App\Models\CompanyMember;
use App\Services\Company\CompanyRemoval;
use Illuminate\Http\RedirectResponse;

class ReactivateCompanyMemberController extends Controller
{
    public function __construct(private CompanyRemoval $removal) {}

    public function __invoke(ReactivateCompanyMemberRequest $request, CompanyMember $member): RedirectResponse
    {
        $this->removal->reactivate($member, $request->user());

        return redirect()->route('company.accounts')->with('status', "A conta de {$member->user->name} foi reativada.");
    }
}
