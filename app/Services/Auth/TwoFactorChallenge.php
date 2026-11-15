<?php

namespace App\Services\Auth;

use App\Exceptions\ActionRefused;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * O segundo passo do login de quem ligou a 2FA. A senha certa (ou a volta do
 * GitHub) não entra: grava na sessão "senha ok, falta o código" (a conta, o
 * "Continuar conectado" e, no GitHub, a ligação pendente), troca o id da
 * sessão e manda para a tela do código. Só o código do app ou um código de
 * recuperação completa o login, em até 5 minutos; depois, é a senha de novo.
 *
 * O estado fica no servidor, na sessão de quem acertou a senha: outra sessão
 * não o tem, e a senha de outra conta troca o estado (o código da primeira não
 * entra na segunda). O admin também passa por aqui, vindo do /admin/login.
 *
 * Limite (TwoFactor::attempt, contado antes da conferência): 5 códigos errados
 * por conta e 20 por IP a cada 15 minutos. Passou, nem o código certo entra. A
 * reautenticação conta à parte, por sessão (só o IP é o mesmo).
 */
class TwoFactorChallenge
{
    public const MINUTES = 5;

    private const KEY = 'two_factor_challenge';

    public function __construct(private TwoFactor $twoFactor, private ReauthCodes $codes, private RejectedAttempts $attempts) {}

    /** A conta precisa do código para entrar? (o admin também, se ligou a 2FA) */
    public static function required(User $user): bool
    {
        return TwoFactor::required($user);
    }

    /** @param  ?array{id: string, login: string, email: string}  $github  a ligação ao GitHub, gravada só depois do código */
    public function begin(User $user, Session $session, bool $remember = false, ?array $github = null): void
    {
        $session->regenerate();
        $session->put(self::KEY, ['user' => $user->id, 'at' => now()->getTimestamp(), 'remember' => $remember, 'github' => $github]);
    }

    /** A conta que espera o código nesta sessão, dentro do prazo. */
    public function pending(Session $session): ?User
    {
        $state = $this->state($session);

        return $state ? User::find($state['user']) : null;
    }

    /**
     * Confere o código e entra. Devolve quantos códigos de recuperação sobraram,
     * quando foi um deles (nulo com o código do app).
     *
     * @throws ValidationException
     */
    public function complete(Request $request, string $code): ?int
    {
        $session = $request->session();
        $state = $this->state($session);
        $user = $state ? User::find($state['user']) : null;
        $record = $user ? TwoFactor::current($user) : null;

        // A empresa suspensa (ou a conta desativada) entre a senha e o código: volta ao login, onde a senha dá a resposta da errada.
        // O admin do Security Monitor também passa por aqui (E0c).
        if ($user === null || ! $record?->confirmed()) {
            $session->forget(self::KEY);

            throw new ActionRefused('O prazo para digitar o código acabou. Entre de novo.', route('login'));
        }

        $area = $user->is_admin ? 'admin' : 'login';

        if (($wait = TwoFactor::attempt($user, $request->ip())) !== null) {
            $this->attempts->record($request, $area.'_codigo_trava', $user->email);

            throw self::refuse('Muitas tentativas erradas. Espere '.ceil($wait / 60).' min e entre de novo com a senha.');
        }

        $used = $this->twoFactor->check($record, $code);

        if ($used === null) {
            $this->attempts->record($request, $area.'_codigo', $user->email);

            // A trava da conta acabou de fechar: quem errou sabia a senha. A conta é avisada, uma vez por trava.
            if (TwoFactor::accountLocked($user) && Cache::add('two-factor-lock-mail:'.$user->id, true, 900)) {
                $this->twoFactor->notify($user, 'locked');
            }

            throw self::refuse(preg_match('/[A-Za-z]/', $code) === 1
                ? 'Este código de recuperação não confere ou já foi usado. Confira as letras e o hífen.'
                : 'O código não confere. Digite o que o app mostra agora. Se continuar errado, confira se a data e a hora do celular estão no automático.');
        }

        TwoFactor::passed($user, $request->ip());
        $session->forget(self::KEY);

        if ($state['github']) {
            GithubSignIn::link($user, $state['github']);
        }

        Auth::login($user, (bool) $state['remember']);
        $session->regenerate();
        $this->codes->rememberDestinations($user, $session);

        return $used === 'recovery' ? count(TwoFactor::current($user)?->recovery_codes ?? []) : null;
    }

    /** @return array{user: int, at: int, remember: bool, github: ?array}|null */
    private function state(Session $session): ?array
    {
        $state = $session->get(self::KEY);

        if (! is_array($state) || now()->getTimestamp() - (int) ($state['at'] ?? 0) >= self::MINUTES * 60) {
            $session->forget(self::KEY);

            return null;
        }

        return $state;
    }

    private static function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['two_factor_code' => $message]);
    }
}
