<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmTwoFactorRequest;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;

class ConfirmTwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function __invoke(ConfirmTwoFactorRequest $request): RedirectResponse
    {
        $codes = $this->twoFactor->confirm($request->user(), $request->validated('two_factor_code'), $request->session()->getId());

        return redirect()->route('account.two-factor')
            ->with('status', 'Verificação em duas etapas ligada.')
            ->with('recovery_codes', encrypt($codes));
    }
}
