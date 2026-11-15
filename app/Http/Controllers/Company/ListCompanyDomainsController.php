<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\Auth\Reauthentication;
use App\Services\Company\CompanyDomains;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListCompanyDomainsController extends Controller
{
    public function __construct(private CompanyDomains $domains, private Reauthentication $reauth) {}

    public function __invoke(Request $request): View
    {
        return view('company.domains', $this->domains->overview($request->user()) + ['reauth' => $this->reauth->options($request->user(), $request->session())]);
    }
}
