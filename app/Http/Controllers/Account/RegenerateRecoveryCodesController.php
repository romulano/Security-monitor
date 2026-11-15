<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RegenerateRecoveryCodesRequest;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;

class RegenerateRecoveryCodesController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function __invoke(RegenerateRecoveryCodesRequest $request): RedirectResponse
    {
        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());

        return redirect()->route('account.two-factor')
            ->with('status', 'Códigos novos gerados. Os anteriores não valem mais.')
            ->with('recovery_codes', encrypt($codes));
    }
}
