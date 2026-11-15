<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;

class ShowTwoFactorChallengeRequest extends Request
{
    /** `?recuperacao=1`: o campo vira o do código de recuperação (texto), no lugar do código do app (números). */
    public function recovery(): bool
    {
        return $this->boolean('recuperacao');
    }
}
