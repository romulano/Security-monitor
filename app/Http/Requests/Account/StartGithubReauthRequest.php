<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Request;
use App\Services\Auth\GithubOAuth;

class StartGithubReauthRequest extends Request
{
    /** Sem o app do GitHub configurado no .env a rota nem existe (404). */
    public function authorize(): bool
    {
        return GithubOAuth::configured();
    }

    /** A página de onde a pessoa saiu, para voltar a ela depois do GitHub. Endereço de outro site não vale. */
    public function back(): string
    {
        $previous = url()->previous(route('account.show'));

        return parse_url($previous, PHP_URL_HOST) === $this->getHost() ? $previous : route('account.show');
    }
}
