<?php

namespace App\Http\Controllers\Admin\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Companies\DisableCompanyRequest;
use App\Models\Company;
use App\Services\Company\Companies;
use Illuminate\Http\RedirectResponse;

class DisableCompanyController extends Controller
{
    public function __construct(private Companies $companies) {}

    public function __invoke(DisableCompanyRequest $request, Company $company): RedirectResponse
    {
        $this->companies->disable($company, $request->user());

        return redirect()->route('admin.companies.index')->with('status', "Chave de {$company->name} desligada.");
    }
}
