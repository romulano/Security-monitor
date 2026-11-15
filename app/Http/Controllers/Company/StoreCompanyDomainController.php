<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyDomainRequest;
use App\Services\Company\CompanyDomains;
use Illuminate\Http\RedirectResponse;

class StoreCompanyDomainController extends Controller
{
    public function __construct(private CompanyDomains $domains) {}

    public function __invoke(StoreCompanyDomainRequest $request): RedirectResponse
    {
        $owned = $this->domains->add($request->user(), $request->validated('dominio'), $request->sectorId());

        return redirect()->to(route('company.domains').'#dominio-'.$owned->id)
            ->with('status', "{$owned->domain} cadastrado. Crie o registro TXT abaixo e clique em Verificar agora.");
    }
}
