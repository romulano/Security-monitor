<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyTwoFactorRequest;
use App\Services\Company\CompanyTwoFactor;
use Illuminate\Http\RedirectResponse;

/** Exigir (ou não) a 2FA de todas as contas da empresa. */
class UpdateCompanyTwoFactorController extends Controller
{
    public function __construct(private CompanyTwoFactor $twoFactor) {}

    public function __invoke(UpdateCompanyTwoFactorRequest $request): RedirectResponse
    {
        $required = $request->boolean('required');
        $this->twoFactor->setRequired($request->user(), $required);

        return back()->with('status', $required
            ? 'Pronto: a verificação em duas etapas é exigida. Quem ainda não ligou recebeu um e-mail.'
            : 'Pronto: a verificação em duas etapas voltou a ser opcional.');
    }
}
