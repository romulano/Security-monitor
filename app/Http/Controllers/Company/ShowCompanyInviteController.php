<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ShowCompanyInviteRequest;
use App\Services\Company\CompanyInvites;
use Illuminate\View\View;

class ShowCompanyInviteController extends Controller
{
    public function __construct(private CompanyInvites $invites) {}

    public function __invoke(ShowCompanyInviteRequest $request, string $token): View
    {
        return view('company.invite', ['invite' => $this->invites->findPending($token), 'token' => $token]);
    }
}
