<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;
use App\Services\Auth\GithubOAuth;
use App\Support\Turnstile;

/** O cadastro com o GitHub parte do mesmo formulário do cadastro: o captcha vem dele. */
class GithubRegisterRequest extends Request
{
    public function authorize(): bool
    {
        return GithubOAuth::configured();
    }

    protected function passedValidation(): void
    {
        Turnstile::verify($this, 'cadastro');
    }
}
