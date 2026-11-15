<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CancelCompanyInviteRequest;
use App\Models\CompanyInvite;
use App\Services\Company\CompanyInvites;
use Illuminate\Http\RedirectResponse;

class CancelCompanyInviteController extends Controller
{
    public function __construct(private CompanyInvites $invites) {}

    public function __invoke(CancelCompanyInviteRequest $request, CompanyInvite $invite): RedirectResponse
    {
        $this->invites->cancel($invite, $request->user());

        return redirect()->route('company.accounts')->with('status', "Convite para {$invite->email} cancelado. A vaga ficou livre.");
    }
}
