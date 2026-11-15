<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\Registration;
use Illuminate\Http\RedirectResponse;

class RegisterController extends Controller
{
    public function __construct(private Registration $registration) {}

    public function __invoke(RegisterRequest $request): RedirectResponse
    {
        $user = $this->registration->register($request->validated(), $request->session());

        return redirect()->route('verification.notice')->with('status', "Conta criada. Enviamos um link de confirmação para {$user->email}.");
    }
}
