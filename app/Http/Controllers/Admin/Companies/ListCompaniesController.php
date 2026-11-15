<?php

namespace App\Http\Controllers\Admin\Companies;

use App\Http\Controllers\Controller;
use App\Services\Admin\CompanyPanel;
use App\Services\Company\Companies;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListCompaniesController extends Controller
{
    public function __construct(private CompanyPanel $panel, private Companies $companies) {}

    public function __invoke(Request $request): View
    {
        return view('admin.companies', [
            'companies' => $this->panel->list(),
            'service' => $this->companies,
            'defaults' => config('secmon.enterprise.limits'),
            'occupant' => $this->panel->occupant($request->old('admin_email')),
        ]);
    }
}
