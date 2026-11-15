<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ShowCompanyEntryRequest;
use App\Models\User;
use Illuminate\View\View;

class ShowCompanyEntryController extends Controller
{
    public function __invoke(ShowCompanyEntryRequest $request, User $user): View
    {
        return view('company.entry', ['account' => $user, 'company' => $user->company, 'action' => $request->fullUrl()]);
    }
}
