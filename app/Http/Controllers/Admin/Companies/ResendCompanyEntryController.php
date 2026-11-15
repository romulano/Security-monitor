<?php

namespace App\Http\Controllers\Admin\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Companies\ResendCompanyEntryRequest;
use App\Models\Company;
use App\Services\Company\CompanyEntry;
use Illuminate\Http\RedirectResponse;

class ResendCompanyEntryController extends Controller
{
    public function __construct(private CompanyEntry $entry) {}

    public function __invoke(ResendCompanyEntryRequest $request, Company $company): RedirectResponse
    {
        $this->entry->resend($company, $request->user());

        return redirect()->route('admin.companies.index')->with('status', "Link novo enviado à admin de {$company->name}. Os links de antes deixaram de valer.");
    }
}
