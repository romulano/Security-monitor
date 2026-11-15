<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\RemoveCompanyDomainRequest;
use App\Services\Company\CompanyDomains;
use Illuminate\Http\RedirectResponse;

class RemoveCompanyDomainController extends Controller
{
    public function __construct(private CompanyDomains $domains) {}

    public function __invoke(RemoveCompanyDomainRequest $request): RedirectResponse
    {
        $counts = $this->domains->remove($request->user(), $request->domainId(), $request->expected());

        return redirect()->route('company.domains')->with('status', 'Domínio removido. '.CompanyDomains::summary($counts));
    }
}
