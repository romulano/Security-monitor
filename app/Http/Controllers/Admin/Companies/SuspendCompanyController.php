<?php

namespace App\Http\Controllers\Admin\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Companies\SuspendCompanyRequest;
use App\Models\Company;
use App\Services\Company\Companies;
use Illuminate\Http\RedirectResponse;

class SuspendCompanyController extends Controller
{
    public function __construct(private Companies $companies) {}

    public function __invoke(SuspendCompanyRequest $request, Company $company): RedirectResponse
    {
        $this->companies->suspend($company, $request->user());

        return redirect()->route('admin.companies.index')->with('status', "Empresa {$company->name} suspensa: os dados ficam, o acesso e os envios param até reativar.");
    }
}
