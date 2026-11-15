<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\Auth\Reauthentication;
use App\Services\Company\CompanyAccounts;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListCompanyAccountsController extends Controller
{
    public function __construct(private CompanyAccounts $accounts, private Reauthentication $reauth) {}

    public function __invoke(Request $request): View
    {
        return view('company.accounts', $this->accounts->overview($request->user()) + ['reauth' => $this->reauth->options($request->user(), $request->session())]);
    }
}
