<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DisableTwoFactorRequest;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;

class DisableTwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function __invoke(DisableTwoFactorRequest $request): RedirectResponse
    {
        $this->twoFactor->disable($request->user());

        return redirect()->route('account.two-factor')->with('status', 'Verificação em duas etapas desligada.');
    }
}
