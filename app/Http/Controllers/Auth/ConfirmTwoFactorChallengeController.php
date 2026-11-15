<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorChallengeRequest;
use App\Services\Auth\TwoFactorChallenge;
use Illuminate\Http\RedirectResponse;

class ConfirmTwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorChallenge $challenge) {}

    public function __invoke(ConfirmTwoFactorChallengeRequest $request): RedirectResponse
    {
        $left = $this->challenge->complete($request, $request->validated('two_factor_code'));
        $redirect = redirect()->intended(route($request->user()->is_admin ? 'admin.dashboard' : 'account.show'));

        if ($left === null) {
            return $redirect;
        }

        $more = 'Gere códigos novos em Minha conta → Verificação em duas etapas.';

        return $redirect->with('status', match (true) {
            $left === 0 => "Você entrou com o último código de recuperação. {$more}",
            $left === 1 => "Você entrou com um código de recuperação, que agora não vale mais. Resta 1. {$more}",
            $left <= 3 => "Você entrou com um código de recuperação, que agora não vale mais. Restam {$left}. {$more}",
            default => "Você entrou com um código de recuperação, que agora não vale mais. Restam {$left}.",
        });
    }
}
