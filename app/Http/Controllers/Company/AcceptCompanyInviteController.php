<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\AcceptCompanyInviteRequest;
use App\Services\Company\CompanyInvites;
use Illuminate\Http\RedirectResponse;

class AcceptCompanyInviteController extends Controller
{
    public function __construct(private CompanyInvites $invites) {}

    public function __invoke(AcceptCompanyInviteRequest $request, string $token): RedirectResponse
    {
        $this->invites->accept($token, $request->validated('name'), $request->validated('password'), $request->session());

        return redirect()->route('account.show')->with('status', 'Convite aceito. A sua conta da empresa está pronta.');
    }
}
