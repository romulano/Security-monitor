<?php

namespace App\Http\Requests\Auth;

class AdminLoginRequest extends CredentialsRequest
{
    protected function turnstileAction(): string
    {
        return 'admin-login';
    }
}
