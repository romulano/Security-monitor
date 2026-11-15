<?php

namespace App\Http\Controllers\Admin\Companies;

use App\Exceptions\ActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Companies\StoreCompanyRequest;
use App\Services\Company\Companies;
use App\Services\Company\CompanyEntry;
use Illuminate\Http\RedirectResponse;

class StoreCompanyController extends Controller
{
    public function __construct(private Companies $companies, private CompanyEntry $entry) {}

    public function __invoke(StoreCompanyRequest $request): RedirectResponse
    {
        try {
            $company = $this->companies->create($request->validated('name'), $request->validated('admin_name'), $request->validated('admin_email'), $request->user());
        } catch (ActionRefused $e) {
            // O formulário volta preenchido, e a tela mostra quem ocupa o e-mail (CompanyPanel::occupant).
            return back()->withInput($request->only(['name', 'admin_name', 'admin_email']))->with('error', $e->getMessage());
        }

        $sent = $this->entry->invitePending($company, $request->user());

        return redirect()->route('admin.companies.index')->with('status', "Empresa {$company->name} criada, com a conta admin {$request->validated('admin_email')}. "
            .($sent ? 'O link para ela escolher a senha já foi enviado.' : 'O link para ela escolher a senha sai quando você ligar a chave da empresa.'));
    }
}
