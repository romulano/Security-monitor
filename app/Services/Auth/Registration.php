<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro aberto, com captcha (no form request) e o e-mail confirmado por link
 * antes de usar os alertas. A conta já entra logada.
 */
class Registration
{
    public const EMAIL_TAKEN = 'Já existe uma conta com este e-mail.';

    public function __construct(private EmailVerification $verification) {}

    /**
     * O cadastro pede nome, e-mail e senha: a senha é a que a pessoa digitou,
     * então não há nada para mostrar depois. O link de confirmação sai em seguida.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data, Session $session): User
    {
        $user = $this->enter(['name' => $data['name'], 'email' => $data['email']], $data['password'], $session);

        $this->verification->send($user);

        return $user;
    }

    /**
     * O mesmo cadastro com os dados do GitHub (nome e e-mail verificado vêm de
     * lá, então a conta já nasce confirmada). A conta também recebe uma senha
     * inicial gerada (devolvida para ser mostrada uma vez), para quem quiser
     * entrar pelo e-mail.
     *
     * @param  array{id: string, login: string, name: string, email: string}  $github
     */
    public function registerWithGithub(array $github, Session $session): string
    {
        $password = Str::password(16, symbols: false);

        $this->enter([
            'name' => $github['name'],
            'email' => $github['email'],
            'github_id' => $github['id'],
            'github_login' => $github['login'],
            'email_verified_at' => now(),
        ], $password, $session);

        return $password;
    }

    /**
     * Dois cadastros juntos com o mesmo e-mail (em qualquer caixa) passam pela regra e só o índice do banco
     * barra o segundo: ele recebe o mesmo erro de "e-mail já usado" que a regra daria, nunca um 500.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function enter(array $attributes, string $password, Session $session): User
    {
        try {
            // Numa transação própria: o insert recusado não derruba a transação de quem chamou.
            $user = DB::transaction(fn () => User::forceCreate($attributes + ['password' => $password, 'is_admin' => false]));
        } catch (UniqueConstraintViolationException $e) {
            throw str_contains($e->getMessage(), 'email')
                ? ValidationException::withMessages(['email' => self::EMAIL_TAKEN])
                : $e;
        }

        Auth::login($user);
        $session->regenerate();

        return $user;
    }
}
