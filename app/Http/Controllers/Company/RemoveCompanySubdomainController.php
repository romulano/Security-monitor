<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\RemoveCompanySubdomainRequest;
use App\Models\OwnedSubdomain;
use App\Services\Domains\CompanySubdomains;
use Illuminate\Http\RedirectResponse;

class RemoveCompanySubdomainController extends Controller
{
    public function __construct(private CompanySubdomains $subdomains) {}

    public function __invoke(RemoveCompanySubdomainRequest $request, OwnedSubdomain $subdomain): RedirectResponse
    {
        $this->subdomains->remove($request->user(), $subdomain);

        return back()->with('status', "{$subdomain->name} saiu dos subdomínios. A descoberta automática não o traz de volta; dá para cadastrar de novo.");
    }
}
