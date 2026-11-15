<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerifyEmailRequest;
use App\Services\Auth\EmailVerification;
use Illuminate\Http\RedirectResponse;

class ResendVerifyEmailController extends Controller
{
    public function __construct(private EmailVerification $verification) {}

    public function __invoke(ResendVerifyEmailRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('alerts.index');
        }

        $this->verification->send($user);

        return back()->with('status', "Enviamos um link novo para {$user->email}.");
    }
}
