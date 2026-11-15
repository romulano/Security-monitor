<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\AddCompanySectorMemberRequest;
use App\Models\Sector;
use App\Services\Company\Sectors;
use Illuminate\Http\RedirectResponse;

class AddCompanySectorMemberController extends Controller
{
    public function __construct(private Sectors $sectors) {}

    public function __invoke(AddCompanySectorMemberRequest $request, Sector $sector): RedirectResponse
    {
        $this->sectors->addMember($request->user(), $sector, $request->memberId());

        return redirect()->route('company.sectors')->with('status', "Conta adicionada ao setor {$sector->name}.");
    }
}
