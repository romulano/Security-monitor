<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;
use App\Services\Auth\GithubOAuth;

class GithubLoginRequest extends Request
{
    /** Sem o app do GitHub configurado no .env a rota nem existe (404). */
    public function authorize(): bool
    {
        return GithubOAuth::configured();
    }
}
