<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanySectorManagerRequest;
use App\Models\CompanyMember;
use App\Models\Sector;
use App\Services\Company\Sectors;
use Illuminate\Http\RedirectResponse;

class UpdateCompanySectorManagerController extends Controller
{
    public function __construct(private Sectors $sectors) {}

    public function __invoke(UpdateCompanySectorManagerRequest $request, Sector $sector, CompanyMember $member): RedirectResponse
    {
        $this->sectors->setManager($request->user(), $sector, $member, $request->manager());
        $name = $member->user->name;

        return redirect()->route('company.sectors')->with('status', $request->manager()
            ? "{$name} agora responde pelo setor {$sector->name}."
            : "{$name} deixou de responder pelo setor {$sector->name}.");
    }
}
