<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Models\User;
use App\Services\Auth\EmailVerification;
use Illuminate\Http\RedirectResponse;

/** O link do e-mail de confirmação; abre logado ou não (a assinatura é a prova). */
class VerifyEmailController extends Controller
{
    public function __construct(private EmailVerification $verification) {}

    public function __invoke(VerifyEmailRequest $request, User $user): RedirectResponse
    {
        $new = $this->verification->verify($user);
        $status = $new ? 'E-mail confirmado. Os alertas estão liberados.' : 'Este e-mail já estava confirmado.';

        // Aberto em outro aparelho (sem login): a confirmação vale, e a pessoa entra para usar.
        return $request->user()?->is($user)
            ? redirect()->route('alerts.index')->with('status', $status)
            : redirect()->route('login')->with('status', $status.' Entre para usar.');
    }
}
