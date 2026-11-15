<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ChangeCompanyDomainSectorRequest;
use App\Models\OwnedDomain;
use App\Services\Company\CompanyDomains;
use Illuminate\Http\RedirectResponse;

class ChangeCompanyDomainSectorController extends Controller
{
    public function __construct(private CompanyDomains $domains) {}

    public function __invoke(ChangeCompanyDomainSectorRequest $request, OwnedDomain $domain): RedirectResponse
    {
        $this->domains->move($request->user(), $domain, $request->sectorId());

        return redirect()->to(route('company.domains').'#dominio-'.$domain->id)->with('status', "Setor de {$domain->domain} trocado.");
    }
}
