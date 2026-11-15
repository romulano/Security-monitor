<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ListCompanyActivityRequest;
use App\Services\Company\CompanyAccounts;
use App\Services\Company\CompanyAudit;
use Illuminate\View\View;

class ListCompanyActivityController extends Controller
{
    public function __construct(private CompanyAccounts $accounts, private CompanyAudit $audit) {}

    public function __invoke(ListCompanyActivityRequest $request): View
    {
        $company = $this->accounts->companyOf($request->user());

        return view('company.activity', [
            'company' => $company,
            'entries' => $this->audit->list($company, $request->filters()),
            'actors' => $this->audit->actors($company),
            'filters' => $request->filters(),
        ]);
    }
}
