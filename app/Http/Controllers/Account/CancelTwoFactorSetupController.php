<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\CancelTwoFactorSetupRequest;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;

class CancelTwoFactorSetupController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function __invoke(CancelTwoFactorSetupRequest $request): RedirectResponse
    {
        $this->twoFactor->cancel($request->user());

        return redirect()->route('account.two-factor')->with('status', 'Configuração cancelada.');
    }
}
