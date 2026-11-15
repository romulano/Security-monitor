<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmReauthCodeRequest;
use App\Services\Auth\ReauthCodes;
use App\Services\Auth\Reauthentication;
use Illuminate\Http\RedirectResponse;

/** Confere o código da reautenticação e volta à página de onde ele foi pedido. */
class ConfirmReauthCodeController extends Controller
{
    public function __construct(private ReauthCodes $codes) {}

    public function __invoke(ConfirmReauthCodeRequest $request): RedirectResponse
    {
        $this->codes->confirm($request->user(), $request->code(), $request->session());

        // Com a 2FA, o código no app é o primeiro fator: falta o do app autenticador.
        return back()->with('status', Reauthentication::needsTwoFactor($request->user(), $request->session())
            ? 'Código confirmado. Agora digite o código do app autenticador.'
            : 'Pronto: você confirmou que é você com o código. Vale por 10 minutos.');
    }
}
