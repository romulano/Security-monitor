<?php

namespace App\Http\Requests\Auth;

class LoginRequest extends CredentialsRequest
{
    protected function turnstileAction(): string
    {
        return 'login';
    }
}
