<?php

namespace App\Services\Auth;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reautenticação recente: antes de uma ação sensível, a pessoa confirma que é
 * ela mesma, além de estar logada. Quem tem só a sessão (um cookie roubado)
 * não passa. A confirmação vale 10 minutos, nesta sessão e para este usuário,
 * e o método fica guardado para o registro da ação (`password`, `github` ou
 * `code`). Na rota, é o middleware `reauth` (com `reauth:dominio`, a ação é o
 * aceite de um teste de segurança no domínio da rota).
 *
 * A senha e o GitHub valem para qualquer ação. O código vale só para o que a
 * mensagem dele anunciou (A1 do Red Team): o genérico, para as ações da conta;
 * o que nomeia um domínio, só para o aceite do teste nesse domínio. Assim um
 * código pedido para outra coisa não confirma o aceite, e o código do aceite não
 * troca o e-mail.
 *
 * A senha é conferida aqui, com 5 tentativas a cada 5 minutos por conta. O
 * mesmo limite vale em todo lugar que confere a senha com a sessão aberta (a
 * ação sensível, a troca de e-mail e a troca de senha): sem ele, qualquer um
 * desses formulários servia para adivinhar a senha.
 *
 * Com a 2FA ligada, a senha, o GitHub e o código no app são só o primeiro
 * fator: a confirmação só vale depois do código do app autenticador (ou de um
 * código de recuperação), no mesmo formulário (`reauth_two_factor_code`) ou na
 * caixa "Falta o código do app". Os códigos errados contam por sessão (e no
 * IP), não na conta: quem tem só a senha e erra códigos no login não tranca a
 * reautenticação da dona (H2 do Red Team).
 */
class Reauthentication
{
    public const MINUTES = 10;

    /** Como a pessoa confirmou, para o registro da ação e para a tela. */
    public const METHODS = ['password' => 'com a senha', 'github' => 'pelo GitHub', 'code' => 'com um código'];

    private const KEY = 'reauth';

    /** A chave da trava dos códigos do app nesta sessão (TwoFactor::attempt). */
    private const LOCK = 'reauth_two_factor_lock';

    private const PASSWORD_ATTEMPTS = 5;

    public function __construct(private ReauthCodes $codes, private TwoFactor $twoFactor) {}

    /**
     * Confirmou que é você há menos de 10 minutos, nesta sessão, para esta ação?
     * `$for` nulo: as ações da conta; um domínio: o aceite do teste nele.
     */
    public static function recent(User $user, Session $session, ?string $for = null): bool
    {
        $state = self::state($user, $session);

        return self::valid($state, $for) && self::complete($user, $state);
    }

    /** Há um primeiro fator nesta sessão (para qualquer ação), mas falta o código do app (a conta tem a 2FA)? */
    public static function needsTwoFactor(User $user, Session $session): bool
    {
        $state = self::state($user, $session);

        return $state !== null && ! self::complete($user, $state);
    }

    /** O método da confirmação que ainda vale para esta ação (`password`, `github` ou `code`), ou nulo. */
    public static function method(User $user, Session $session, ?string $for = null): ?string
    {
        $state = self::state($user, $session);

        return self::valid($state, $for) && self::complete($user, $state) ? $state['method'] : null;
    }

    /** Há uma confirmação recente por código, mas pedida para outra ação? (para dizer o porquê na tela) */
    public static function codeForAnother(User $user, Session $session, ?string $for = null): bool
    {
        $state = self::state($user, $session);

        return $state !== null && $state['method'] === 'code' && ! self::valid($state, $for);
    }

    /**
     * Registra a confirmação e troca o id da sessão: quem tiver uma cópia do id
     * antigo (o cookie roubado) segue sem ela, mesmo que tenha levado a dona da
     * conta a confirmar no navegador dela (abrindo o GitHub ou digitando o código).
     * `$scope`: o domínio que o código anunciou (nulo no código genérico).
     *
     * Um código não rebaixa a senha nem o GitHub que ainda valem: eles servem para
     * tudo, e o código só para a ação dele. Sem isso, confirmar um código genérico
     * depois da senha fazia o aceite do teste pedir a senha de novo. A senha continua
     * valendo só até o prazo dela: o código não a estende.
     */
    public static function mark(User $user, string $method, Session $session, ?string $scope = null): void
    {
        $current = self::state($user, $session);

        if ($method !== 'code' || $current === null || $current['method'] === 'code') {
            $session->put(self::KEY, ['user' => $user->id, 'at' => now()->getTimestamp(), 'method' => $method, 'scope' => $method === 'code' ? $scope : null, 'two_factor' => false]);
        }

        $session->regenerate();
    }

    /**
     * Confere a senha da conta e, se bater, registra a confirmação. A recusa
     * volta com o aviso no campo `$field` do formulário (bag `$bag`).
     *
     * @throws ValidationException
     */
    public function confirmPassword(
        User $user,
        ?string $password,
        Session $session,
        string $field = 'reauth_password',
        string $bag = 'reauth',
        string $missing = 'Digite a sua senha para confirmar que é você.',
    ): void {
        $key = 'reauth-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_ATTEMPTS)) {
            throw self::refuse($field, $bag, 'Muitas tentativas. Tente de novo em '.RateLimiter::availableIn($key).'s.');
        }

        if ($password === null || $password === '') {
            throw self::refuse($field, $bag, $missing);
        }

        if (! Hash::check($password, $user->getAuthPassword())) {
            RateLimiter::hit($key, 300);

            throw self::refuse($field, $bag, 'A senha não confere.');
        }

        RateLimiter::clear($key);
        self::mark($user, 'password', $session);
    }

    /**
     * A confirmação recente para a ação, ou a senha que veio junto no formulário
     * (`reauth_password`). Sem nenhuma das duas, recusa com o aviso nesse campo
     * (bag `reauth`), e a ação não roda. `$scope`: o domínio do aceite ou da
     * execução; nulo, as ações da conta. É o que o middleware `reauth` faz, e o
     * Retomar de uma execução também, depois do 404 da execução de outra conta.
     *
     * @throws ValidationException
     */
    public function ensure(User $user, Session $session, ?string $scope, mixed $password, mixed $code = null, string $field = 'reauth_password', string $bag = 'reauth', ?string $missing = null): void
    {
        if (self::recent($user, $session, $scope)) {
            return;
        }

        if (! self::valid(self::state($user, $session), $scope)) {
            $this->confirmPassword($user, is_string($password) ? $password : null, $session, $field, $bag, $missing ?? (self::codeForAnother($user, $session, $scope)
                ? 'O código que você confirmou foi pedido para outra ação. Digite a sua senha ou peça o código nesta página.'
                : 'Digite a sua senha para confirmar que é você.'));
        }

        if (! self::recent($user, $session, $scope)) {
            $this->confirmTwoFactor($user, $session, is_string($code) ? $code : null, request()->ip());
        }
    }

    /**
     * O segundo fator: o código do app (ou um de recuperação) depois da senha, do
     * GitHub ou do código no app, dentro dos 10 minutos deles. A recusa volta no
     * campo `reauth_two_factor_code` (bag `reauth`).
     *
     * @throws ValidationException
     */
    public function confirmTwoFactor(User $user, Session $session, ?string $code, ?string $ip): void
    {
        $state = self::state($user, $session);
        $record = TwoFactor::current($user);

        if ($state === null || ! $record?->confirmed()) {
            throw self::refuse('reauth_two_factor_code', 'reauth', 'Primeiro confirme que é você com a senha, o GitHub ou um código.');
        }

        // A trava é desta sessão (uma chave aleatória guardada nela): o id muda a cada confirmação.
        $lock = $session->get(self::LOCK) ?? tap(Str::random(32), fn (string $key) => $session->put(self::LOCK, $key));

        if (($wait = TwoFactor::locked($user, $ip, $lock)) !== null) {
            throw self::refuse('reauth_two_factor_code', 'reauth', 'Muitas tentativas. Tente de novo em '.ceil($wait / 60).' min.');
        }

        if ($code === null || trim($code) === '') {
            // O passo esperado, não um erro: a bag `reauth_next` não sai em vermelho; o campo do código aparece sozinho.
            throw self::refuse('reauth_two_factor_code', 'reauth_next', 'Agora digite o código do app autenticador.');
        }

        if (($wait = TwoFactor::attempt($user, $ip, $lock)) !== null) {
            throw self::refuse('reauth_two_factor_code', 'reauth', 'Muitas tentativas. Tente de novo em '.ceil($wait / 60).' min.');
        }

        if ($this->twoFactor->check($record, $code) === null) {
            throw self::refuse('reauth_two_factor_code', 'reauth', preg_match('/[A-Za-z]/', $code) === 1
                ? 'Este código de recuperação não confere ou já foi usado. Confira as letras e o hífen.'
                : 'O código não confere. Digite o que o app mostra agora. Se continuar errado, confira se a data e a hora do celular estão no automático.');
        }

        TwoFactor::passed($user, $ip, $lock);
        $session->put(self::KEY, ['at' => now()->getTimestamp(), 'two_factor' => true] + $state);
        $session->regenerate();
    }

    /**
     * O que a caixa "Confirme que é você" mostra: a confirmação que ainda vale
     * (como foi feita e até quando) ou as outras formas de confirmar que a conta
     * tem, além da senha: o GitHub ligado, os apps que podem receber o código e
     * o código já pedido.
     *
     * `$for`: a ação da página (nulo: as da conta; um domínio: o aceite do teste
     * nele). A confirmação e o código pedido só aparecem na página da ação deles.
     *
     * @return array{recent: bool, two_factor: bool, first: ?string, has_two_factor: bool, method: ?string, until: ?Carbon, github: bool, github_login: ?string, channels: Collection<int, NotificationChannel>, pending: ?array{app: string, until: Carbon}}
     */
    public function options(User $user, Session $session, ?string $for = null): array
    {
        $state = self::state($user, $session);
        $state = self::valid($state, $for) ? $state : null;
        $second = $state !== null && ! self::complete($user, $state);
        $first = $second ? $state['method'] : null;
        $state = $second ? null : $state;

        return [
            'recent' => $state !== null,
            // Falta o código do app depois do primeiro fator (a conta tem a 2FA).
            'two_factor' => $second,
            'first' => $first,
            // A conta tem a 2FA: o formulário pede a senha e o código juntos.
            'has_two_factor' => TwoFactor::required($user),
            // Com a 2FA, a confirmação foi feita também com o código do app.
            'method' => $state ? self::METHODS[$state['method']].(($state['two_factor'] ?? false) ? ' e o código do app' : '') : null,
            'until' => $state ? Carbon::createFromTimestamp($state['at'] + self::MINUTES * 60) : null,
            'github' => GithubReauth::available($user),
            'github_login' => $user->github_login,
            'channels' => $this->codes->channels($user, $session),
            'pending' => $this->codes->pending($user, $session, $for),
        ];
    }

    /** Sem a 2FA na conta, o primeiro fator basta; com ela, só depois do código do app. */
    private static function complete(User $user, array $state): bool
    {
        return ($state['two_factor'] ?? false) === true || ! TwoFactor::required($user);
    }

    /** A senha e o GitHub valem para tudo; o código, só para a ação que a mensagem dele anunciou. */
    private static function valid(?array $state, ?string $for): bool
    {
        return $state !== null && ($state['method'] !== 'code' || ($state['scope'] ?? null) === $for);
    }

    /** @return array{user: int, at: int, method: string, scope?: ?string}|null */
    private static function state(User $user, Session $session): ?array
    {
        $state = $session->get(self::KEY);

        if (! is_array($state) || (int) ($state['user'] ?? 0) !== $user->id || ! isset(self::METHODS[$state['method'] ?? ''])) {
            return null;
        }

        $age = now()->getTimestamp() - (int) ($state['at'] ?? 0);

        return $age >= 0 && $age < self::MINUTES * 60 ? $state : null;
    }

    private static function refuse(string $field, string $bag, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message])->errorBag($bag);
    }
}
