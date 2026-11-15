<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GithubLoginRequest;
use App\Services\Auth\GithubSignIn;
use Illuminate\Http\RedirectResponse;

/** "Entrar com o GitHub" em /login: manda a pessoa para a tela de autorização do GitHub. */
class GithubLoginController extends Controller
{
    public function __construct(private GithubSignIn $github) {}

    public function __invoke(GithubLoginRequest $request): RedirectResponse
    {
        return redirect()->away($this->github->loginUrl($request->session()));
    }
}
