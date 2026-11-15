<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\Authenticator;
use Illuminate\Http\RedirectResponse;

/** /login: só usuário comum. */
class LoginController extends Controller
{
    public function __construct(private Authenticator $auth) {}

    public function __invoke(LoginRequest $request): RedirectResponse
    {
        // A senha certa de quem tem a 2FA ainda não entra: falta o código.
        return $this->auth->login($request->credentials(), $request->remember(), admin: false, request: $request)
            ? redirect()->intended(route('account.show'))
            : redirect()->route('login.two-factor');
    }
}
