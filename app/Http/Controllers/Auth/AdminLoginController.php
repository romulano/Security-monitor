<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Services\Auth\Authenticator;
use Illuminate\Http\RedirectResponse;

/** /admin/login: só admin. */
class AdminLoginController extends Controller
{
    public function __construct(private Authenticator $auth) {}

    public function __invoke(AdminLoginRequest $request): RedirectResponse
    {
        // Com a 2FA ligada, a senha certa ainda não entra: falta o código (a mesma tela do login).
        return $this->auth->login($request->credentials(), $request->remember(), admin: true, request: $request)
            ? redirect()->intended(route('admin.dashboard'))
            : redirect()->route('login.two-factor');
    }
}
