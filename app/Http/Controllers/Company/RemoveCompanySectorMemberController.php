<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\RemoveCompanySectorMemberRequest;
use App\Models\CompanyMember;
use App\Models\Sector;
use App\Services\Company\Sectors;
use Illuminate\Http\RedirectResponse;

class RemoveCompanySectorMemberController extends Controller
{
    public function __construct(private Sectors $sectors) {}

    public function __invoke(RemoveCompanySectorMemberRequest $request, Sector $sector, CompanyMember $member): RedirectResponse
    {
        $this->sectors->removeMember($request->user(), $sector, $member);

        return redirect()->route('company.sectors')->with('status', "{$member->user->name} saiu do setor {$sector->name}.");
    }
}
