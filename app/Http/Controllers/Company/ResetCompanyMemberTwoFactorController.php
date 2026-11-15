<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ResetCompanyMemberTwoFactorRequest;
use App\Services\Company\CompanyTwoFactor;
use Illuminate\Http\RedirectResponse;

/** Redefinir a 2FA de uma conta da empresa que perdeu o celular (D11). */
class ResetCompanyMemberTwoFactorController extends Controller
{
    public function __construct(private CompanyTwoFactor $twoFactor) {}

    public function __invoke(ResetCompanyMemberTwoFactorRequest $request): RedirectResponse
    {
        $user = $this->twoFactor->reset($request->user(), (int) $request->validated('member'));

        return back()->with('status', "A verificação em duas etapas de {$user->name} foi redefinida. Ela recebeu um e-mail para configurar de novo.");
    }
}
