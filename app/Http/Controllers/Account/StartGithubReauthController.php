<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StartGithubReauthRequest;
use App\Services\Auth\GithubReauth;
use Illuminate\Http\RedirectResponse;

/** "Confirmar com o GitHub": manda a pessoa para a autorização no GitHub; a confirmação acontece na volta. */
class StartGithubReauthController extends Controller
{
    public function __construct(private GithubReauth $github) {}

    public function __invoke(StartGithubReauthRequest $request): RedirectResponse
    {
        return redirect()->away($this->github->start($request->user(), $request->session(), $request->back()));
    }
}
