<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;
use App\Support\Turnstile;

/** E-mail e senha dos dois logins (cliente e admin); muda só a tela do captcha. */
abstract class CredentialsRequest extends Request
{
    /** A tela que o token do Turnstile tem que ter vindo. */
    abstract protected function turnstileAction(): string;

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'E-mail inválido.',
            'password.required' => 'Informe a senha.',
        ];
    }

    /** O captcha vem antes de conferir a senha. */
    protected function passedValidation(): void
    {
        Turnstile::verify($this, $this->turnstileAction());
    }

    /** @return array{email: string, password: string} */
    public function credentials(): array
    {
        return $this->validated();
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }
}
