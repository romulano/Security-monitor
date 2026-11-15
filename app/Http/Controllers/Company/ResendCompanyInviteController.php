<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ResendCompanyInviteRequest;
use App\Models\CompanyInvite;
use App\Services\Company\CompanyInvites;
use Illuminate\Http\RedirectResponse;

class ResendCompanyInviteController extends Controller
{
    public function __construct(private CompanyInvites $invites) {}

    public function __invoke(ResendCompanyInviteRequest $request, CompanyInvite $invite): RedirectResponse
    {
        $this->invites->resend($invite, $request->user());

        return redirect()->route('company.accounts')->with('status', "Convite reenviado para {$invite->email}. O link anterior deixou de valer.");
    }
}
