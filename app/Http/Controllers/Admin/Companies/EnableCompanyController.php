<?php

namespace App\Http\Controllers\Admin\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Companies\EnableCompanyRequest;
use App\Models\Company;
use App\Services\Company\Companies;
use App\Services\Company\CompanyEntry;
use Illuminate\Http\RedirectResponse;

class EnableCompanyController extends Controller
{
    public function __construct(private Companies $companies, private CompanyEntry $entry) {}

    public function __invoke(EnableCompanyRequest $request, Company $company): RedirectResponse
    {
        $this->companies->enable($company, $request->user());

        $sent = $this->entry->invitePending($company, $request->user());

        return redirect()->route('admin.companies.index')->with('status', "Chave de {$company->name} ligada.".($sent ? ' O link para a admin escolher a senha foi enviado.' : ''));
    }
}
