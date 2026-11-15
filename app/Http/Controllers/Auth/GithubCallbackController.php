<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GithubCallbackRequest;
use App\Services\Auth\GithubSignIn;
use Illuminate\Http\RedirectResponse;

/** A volta do GitHub, do login e do cadastro: o que a sessão guardou na ida diz qual dos dois é. */
class GithubCallbackController extends Controller
{
    public function __construct(private GithubSignIn $github) {}

    public function __invoke(GithubCallbackRequest $request): RedirectResponse
    {
        [, $password] = $this->github->finish($request->session(), $request->state(), $request->code());

        // Com a 2FA ligada, a volta do GitHub não entra: falta o código.
        if ($request->user() === null) {
            return redirect()->route('login.two-factor');
        }

        $redirect = redirect()->intended(route('account.show'));

        return $password ? $redirect->with('initial_password', $password) : $redirect;
    }
}
