<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompleteCompanyEntryRequest;
use App\Models\User;
use App\Services\Company\CompanyEntry;
use Illuminate\Http\RedirectResponse;

class CompleteCompanyEntryController extends Controller
{
    public function __construct(private CompanyEntry $entry) {}

    public function __invoke(CompleteCompanyEntryRequest $request, User $user): RedirectResponse
    {
        $this->entry->complete($user, (string) $request->query('stamp'), $request->validated('password'), $request->session());

        return redirect()->route('account.show')->with('status', 'Senha escolhida e e-mail confirmado. Você entrou na conta da empresa.');
    }
}
