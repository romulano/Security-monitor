<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StartTwoFactorRequest;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;

class StartTwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function __invoke(StartTwoFactorRequest $request): RedirectResponse
    {
        $this->twoFactor->start($request->user());

        return redirect()->route('account.two-factor');
    }
}
