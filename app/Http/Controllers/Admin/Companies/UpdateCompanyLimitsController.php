<?php

namespace App\Http\Controllers\Admin\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Companies\UpdateCompanyLimitsRequest;
use App\Models\Company;
use App\Services\Company\Companies;
use Illuminate\Http\RedirectResponse;

class UpdateCompanyLimitsController extends Controller
{
    public function __construct(private Companies $companies) {}

    public function __invoke(UpdateCompanyLimitsRequest $request, Company $company): RedirectResponse
    {
        $this->companies->setLimits($company, $request->limits(), $request->user());

        return redirect()->route('admin.companies.index')->with('status', "Limites de {$company->name} salvos.");
    }
}
