<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;
use App\Services\Auth\GithubOAuth;

class GithubCallbackRequest extends Request
{
    public function authorize(): bool
    {
        return GithubOAuth::configured();
    }

    /** O state que o GitHub devolveu, para conferir com o da sessão. */
    public function state(): string
    {
        $state = $this->query('state');

        return is_string($state) ? $state : '';
    }

    /** O código da autorização; nulo quando a pessoa cancelou na tela do GitHub (`error`) ou ele não veio. */
    public function code(): ?string
    {
        return $this->filled('error') || ! $this->filled('code') ? null : (string) $this->query('code');
    }
}
