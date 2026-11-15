<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Dois logins sobre a mesma tabela de usuários: /login só aceita usuário
 * comum e /admin/login só aceita admin. Credencial da área errada recebe a
 * mesma resposta de senha errada, para não revelar que a conta existe. A
 * senha errada e a trava ficam registradas (RejectedAttempts). Com a 2FA
 * ligada (na conta comum e na do admin), a senha certa não entra: fica
 * faltando o código (TwoFactorChallenge).
 */
class Authenticator
{
    public function __construct(private ReauthCodes $codes, private RejectedAttempts $attempts, private TwoFactorChallenge $challenge) {}

    /**
     * Devolve se entrou; falso: a senha conferiu, e falta o código da 2FA.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    public function login(array $credentials, bool $remember, bool $admin, Request $request): bool
    {
        $key = 'login:'.mb_strtolower($credentials['email']).'|'.$request->ip();
        $area = $admin ? 'admin' : 'login';

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->attempts->record($request, $area.'_trava', $credentials['email']);

            throw ValidationException::withMessages([
                'email' => 'Muitas tentativas. Tente de novo em '.RateLimiter::availableIn($key).'s.',
            ]);
        }

        // Uma regra só, nesta ordem: a conta de empresa inativa (as duas chaves e sem suspensão, D7, e a conta
        // não desativada, como no EnsureCompanyAccountActive) recebe a resposta da senha errada e nunca abre o
        // desafio da 2FA; senão, quem tem a 2FA não entra aqui e fica guardado para o código; senão, entra.
        $needsCode = false;
        $allowed = function (User $user) use (&$needsCode) {
            return ! ($needsCode = TwoFactorChallenge::required($user));
        };

        // O e-mail sem diferença de caixa: o índice único em lower(email) garante uma conta por e-mail.
        // O lower() do banco dos dois lados: o mb_strtolower do PHP discorda dele fora do ASCII (İ vira "i̇" aqui e "i" lá).
        $email = $credentials['email'];
        $lookup = ['email' => fn ($query) => $query->whereRaw('lower(email) = lower(?)', [$email]), 'password' => $credentials['password'], 'is_admin' => $admin];
        $entered = Auth::attemptWhen($lookup, $allowed, $remember);

        if ($needsCode) {
            RateLimiter::clear($key);
            $this->challenge->begin(Auth::getLastAttempted(), $request->session(), $remember);

            return false;
        }

        if (! $entered) {
            RateLimiter::hit($key, 300);
            $this->attempts->record($request, $area.'_senha', $credentials['email']);

            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        // Os apps privados que a conta tem agora: só eles recebem o código da reautenticação nesta sessão.
        $this->codes->rememberDestinations(Auth::user(), $request->session());

        return true;
    }

    public function logout(Session $session): void
    {
        Auth::logout();

        $session->invalidate();
        $session->regenerateToken();
    }
}
