<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\GithubReauthCallbackRequest;
use App\Services\Auth\GithubReauth;
use App\Services\Auth\Reauthentication;
use Illuminate\Http\RedirectResponse;

/** A volta do GitHub na reautenticação: confere que é o GitHub ligado à conta e volta à página de onde a pessoa saiu. */
class GithubReauthCallbackController extends Controller
{
    public function __construct(private GithubReauth $github) {}

    public function __invoke(GithubReauthCallbackRequest $request): RedirectResponse
    {
        $back = $this->github->finish($request->user(), $request->session(), $request->state(), $request->code());

        return redirect($back)->with('status', Reauthentication::needsTwoFactor($request->user(), $request->session())
            ? 'GitHub confirmado. Agora digite o código do app autenticador.'
            : 'Pronto: você confirmou que é você pelo GitHub. Vale por 10 minutos.');
    }
}
