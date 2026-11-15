<?php

namespace App\Http\Controllers\Admin\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Companies\ResumeCompanyRequest;
use App\Models\Company;
use App\Services\Company\Companies;
use Illuminate\Http\RedirectResponse;

class ResumeCompanyController extends Controller
{
    public function __construct(private Companies $companies) {}

    public function __invoke(ResumeCompanyRequest $request, Company $company): RedirectResponse
    {
        $this->companies->resume($company, $request->user());

        return redirect()->route('admin.companies.index')->with('status', "Empresa {$company->name} reativada.");
    }
}
