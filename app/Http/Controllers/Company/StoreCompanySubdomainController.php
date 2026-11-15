<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanySubdomainRequest;
use App\Models\OwnedDomain;
use App\Services\Domains\CompanySubdomains;
use Illuminate\Http\RedirectResponse;

class StoreCompanySubdomainController extends Controller
{
    public function __construct(private CompanySubdomains $subdomains) {}

    public function __invoke(StoreCompanySubdomainRequest $request, OwnedDomain $domain): RedirectResponse
    {
        $sub = $this->subdomains->add($request->user(), $domain, $request->name());

        return back()->with('status', $sub->isOutside()
            ? "{$sub->name} cadastrado. Hospedado fora: {$sub->reasonLabel()}."
            : "{$sub->name} cadastrado.");
    }
}
