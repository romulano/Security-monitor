<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GithubRegisterRequest;
use App\Services\Auth\GithubSignIn;
use Illuminate\Http\RedirectResponse;

/** "Cadastrar com o GitHub" em /cadastro: o captcha já passou; manda para o GitHub e a conta nasce na volta. */
class GithubRegisterController extends Controller
{
    public function __construct(private GithubSignIn $github) {}

    public function __invoke(GithubRegisterRequest $request): RedirectResponse
    {
        return redirect()->away($this->github->registerUrl($request->session()));
    }
}
