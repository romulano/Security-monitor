<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ShowTwoFactorChallengeRequest;
use App\Services\Auth\TwoFactorChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** A tela do código, depois da senha certa (ou da volta do GitHub) de quem ligou a 2FA. */
class ShowTwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorChallenge $challenge) {}

    public function __invoke(ShowTwoFactorChallengeRequest $request): View|RedirectResponse
    {
        $user = $this->challenge->pending($request->session());

        if ($user === null) {
            return redirect()->route('login')->with('error', 'O prazo para digitar o código acabou. Entre de novo.');
        }

        return view('auth.two-factor', ['minutes' => TwoFactorChallenge::MINUTES, 'recovery' => $request->recovery(), 'admin' => $user->is_admin]);
    }
}
