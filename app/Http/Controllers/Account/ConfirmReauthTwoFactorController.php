<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmReauthTwoFactorRequest;
use App\Services\Auth\Reauthentication;
use Illuminate\Http\RedirectResponse;

/** O código do app depois da senha, do GitHub ou do código no app, de quem tem a 2FA; volta à página da ação. */
class ConfirmReauthTwoFactorController extends Controller
{
    public function __construct(private Reauthentication $reauth) {}

    public function __invoke(ConfirmReauthTwoFactorRequest $request): RedirectResponse
    {
        $this->reauth->confirmTwoFactor($request->user(), $request->session(), $request->validated('reauth_two_factor_code'), $request->ip());

        return back()->with('status', 'Pronto: você confirmou que é você. Vale por 10 minutos.');
    }
}
