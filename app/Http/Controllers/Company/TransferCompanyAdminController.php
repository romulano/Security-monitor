<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\TransferCompanyAdminRequest;
use App\Services\Company\CompanyRoles;
use Illuminate\Http\RedirectResponse;

class TransferCompanyAdminController extends Controller
{
    public function __construct(private CompanyRoles $roles) {}

    public function __invoke(TransferCompanyAdminRequest $request): RedirectResponse
    {
        $target = $this->roles->transfer($request->user(), (int) $request->validated('member'));

        return redirect()->route('account.show')->with('status', "{$target->user->name} agora administra a empresa. A sua conta continua na empresa como membro.");
    }
}
