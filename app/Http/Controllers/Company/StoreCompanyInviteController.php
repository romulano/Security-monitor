<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyInviteRequest;
use App\Services\Company\CompanyAccounts;
use App\Services\Company\CompanyInvites;
use Illuminate\Http\RedirectResponse;

class StoreCompanyInviteController extends Controller
{
    public function __construct(private CompanyAccounts $accounts, private CompanyInvites $invites) {}

    public function __invoke(StoreCompanyInviteRequest $request): RedirectResponse
    {
        $invite = $this->invites->invite($this->accounts->companyOf($request->user()), $request->user(), $request->validated('email'), $request->role(), $request->sectorIds());

        return redirect()->route('company.accounts')->with('status', "Convite enviado para {$invite->email}. Ele vale por 7 dias.");
    }
}
