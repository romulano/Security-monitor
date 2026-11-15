<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ShowVerifyEmailRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Para onde o middleware `verified` manda quem ainda não confirmou o e-mail. */
class ShowVerifyEmailController extends Controller
{
    public function __invoke(ShowVerifyEmailRequest $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('alerts.index');
        }

        return view('auth.verify-email', ['email' => $request->user()->email]);
    }
}
