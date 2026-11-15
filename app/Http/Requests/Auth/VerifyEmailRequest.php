<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;

/**
 * O link do e-mail: a assinatura e o prazo vêm do middleware `signed` (que roda
 * antes de a conta ser procurada); aqui, o hash tem que ser o do e-mail que a conta
 * tem agora. O link de um e-mail que já foi trocado é 404.
 */
class VerifyEmailRequest extends Request
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user !== null && hash_equals(sha1($user->getEmailForVerification()), (string) $this->route('hash'));
    }
}
