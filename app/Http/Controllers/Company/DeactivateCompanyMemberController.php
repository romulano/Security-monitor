<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\DeactivateCompanyMemberRequest;
use App\Models\CompanyMember;
use App\Services\Company\CompanyRemoval;
use Illuminate\Http\RedirectResponse;

class DeactivateCompanyMemberController extends Controller
{
    public function __construct(private CompanyRemoval $removal) {}

    public function __invoke(DeactivateCompanyMemberRequest $request, CompanyMember $member): RedirectResponse
    {
        $this->removal->deactivate($member, $request->user());

        return redirect()->route('company.accounts')->with('status', "A conta de {$member->user->name} foi removida e não tem mais acesso. Ela é apagada em 30 dias, se você não a reativar antes.");
    }
}
