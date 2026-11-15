<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LogoutRequest;
use App\Services\Auth\Authenticator;
use Illuminate\Http\RedirectResponse;

class LogoutController extends Controller
{
    public function __construct(private Authenticator $auth) {}

    public function __invoke(LogoutRequest $request): RedirectResponse
    {
        $this->auth->logout($request->session());

        return redirect()->route('feed.index');
    }
}
