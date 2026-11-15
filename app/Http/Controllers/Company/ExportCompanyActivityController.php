<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ExportCompanyActivityRequest;
use App\Services\Company\CompanyAccounts;
use App\Services\Company\CompanyAudit;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportCompanyActivityController extends Controller
{
    public function __construct(private CompanyAccounts $accounts, private CompanyAudit $audit) {}

    public function __invoke(ExportCompanyActivityRequest $request): StreamedResponse
    {
        return $this->audit->export($this->accounts->companyOf($request->user()), $request->filters());
    }
}
