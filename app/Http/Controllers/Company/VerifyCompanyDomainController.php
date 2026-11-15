<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\VerifyCompanyDomainRequest;
use App\Models\OwnedDomain;
use App\Services\Company\CompanyDomains;
use Illuminate\Http\RedirectResponse;

class VerifyCompanyDomainController extends Controller
{
    public function __construct(private CompanyDomains $domains) {}

    public function __invoke(VerifyCompanyDomainRequest $request, OwnedDomain $domain): RedirectResponse
    {
        $proof = $this->domains->verify($request->user(), $domain);

        return redirect()->to(route('company.domains').'#dominio-'.$domain->id)
            ->with('status', "Posse de {$domain->domain} comprovada pelo TXT em {$proof->domain}.");
    }
}
