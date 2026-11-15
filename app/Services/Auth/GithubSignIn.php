<?php

namespace App\Services\Auth;

use App\Exceptions\ActionRefused;
use App\Models\User;
use App\Services\Company\Companies;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Entrar com o GitHub, nas duas portas: o login (quem já tem conta) e o
 * cadastro (aberto, com o captcha do formulário). A conta nasce com o e-mail
 * confirmado, porque o GitHub só entrega e-mail verificado. O admin segue
 * entrando só por senha em /admin/login: o login pelo GitHub não acha conta
 * de admin. Com a 2FA ligada, a volta do GitHub não entra: falta o código.
 */
class GithubSignIn
{
    private const STATE = 'github_oauth';

    public function __construct(private GithubOAuth $github, private Registration $registration, private ReauthCodes $codes, private TwoFactorChallenge $challenge) {}

    public function loginUrl(Session $session): string
    {
        return $this->authorizeUrl(['mode' => 'login'], $session);
    }

    public function registerUrl(Session $session): string
    {
        return $this->authorizeUrl(['mode' => 'register'], $session);
    }

    /**
     * A volta do GitHub: confere o state (de uso único), descobre quem é a
     * pessoa e entra com a conta dela ou cria a conta. `$code`
     * nulo: ela cancelou na tela do GitHub. Devolve o usuário e, no cadastro,
     * a senha inicial para ser mostrada uma vez.
     *
     * @return array{0: User, 1: ?string}
     */
    public function finish(Session $session, string $state, ?string $code): array
    {
        $expected = $session->pull(self::STATE);
        $back = ($expected['mode'] ?? 'login') === 'register' ? route('register') : route('login');

        if (! is_array($expected) || ! hash_equals((string) ($expected['state'] ?? ''), $state)) {
            throw new ActionRefused('A conexão com o GitHub expirou ou não começou aqui. Tente de novo.', route('login'));
        }

        if ($code === null) {
            throw new ActionRefused('Conexão cancelada no GitHub.', $back);
        }

        $token = $this->github->exchangeCode($code, route('login.github.callback'));

        if (! $token) {
            throw new ActionRefused('O GitHub não confirmou a autorização. Tente de novo.', $back);
        }

        // O token só serve para saber quem a pessoa é.
        try {
            $profile = $this->github->profile($token);
        } finally {
            $this->github->revoke($token);
        }

        if (! $profile) {
            throw new ActionRefused('Não conseguimos ler o seu perfil no GitHub. Tente de novo.', $back);
        }

        if (! $profile['email']) {
            throw new ActionRefused('Sua conta do GitHub não tem um e-mail verificado. Verifique um em github.com/settings/emails e tente de novo.', $back);
        }

        return $expected['mode'] === 'register'
            ? $this->register($profile, $session)
            : [$this->login($profile, $session), null];
    }

    /** @param  array{mode: string}  $flow */
    private function authorizeUrl(array $flow, Session $session): string
    {
        $state = Str::random(40);
        $session->put(self::STATE, ['state' => $state] + $flow);

        return $this->github->authorizeUrl($state, route('login.github.callback'));
    }

    /**
     * Quem já ligou o GitHub entra pelo id. A primeira vez de quem se cadastrou
     * por senha liga sozinha, pelo e-mail que o GitHub verificou.
     *
     * @param  array{id: string, login: string, name: ?string, email: string}  $profile
     */
    private function login(array $profile, Session $session): User
    {
        $user = User::query()->where('github_id', $profile['id'])->first()
            ?? User::query()->whereRaw('lower(email) = lower(?)', [$profile['email']])->whereNull('github_id')->first();

        // Mesma resposta para "não existe" e "é admin": a porta do admin é a senha.
        // A conta de empresa desativada ou com a empresa inativa (chaves, suspensão) também não entra por aqui.
        $closed = $user !== null && app(Companies::class)->accessRefusal($user) !== null;

        if (! $user || $user->is_admin || $closed) {
            throw new ActionRefused('Não há uma conta do Security Monitor ligada a esse GitHub. Crie a sua em Cadastro.', route('login'));
        }

        $link = ['id' => $profile['id'], 'login' => $profile['login'], 'email' => $profile['email']];

        // Com a 2FA, o GitHub vale como a senha: falta o código, e a ligação só é gravada depois dele.
        if (TwoFactorChallenge::required($user)) {
            $this->challenge->begin($user, $session, github: $link);

            return $user;
        }

        self::link($user, $link);
        Auth::login($user);
        $session->regenerate();
        $this->codes->rememberDestinations($user, $session);

        return $user;
    }

    /**
     * Liga o GitHub à conta (ou atualiza o login dele). O GitHub só entrega e-mail
     * verificado: se é o mesmo da conta, ele está confirmado.
     *
     * @param  array{id: string, login: string, email: string}  $profile
     */
    public static function link(User $user, array $profile): void
    {
        $user->forceFill(['github_id' => $profile['id'], 'github_login' => $profile['login']])->save();

        if (! $user->hasVerifiedEmail() && strtolower($user->email) === strtolower($profile['email'])) {
            $user->markEmailAsVerified();
        }
    }

    /**
     * @param  array{id: string, login: string, name: ?string, email: string}  $profile
     * @return array{0: User, 1: string}
     */
    private function register(array $profile, Session $session): array
    {
        if (User::query()->where('github_id', $profile['id'])->exists()) {
            throw new ActionRefused('Esse GitHub já está ligado a uma conta. Entre com ele.', route('login'));
        }

        if (User::query()->whereRaw('lower(email) = lower(?)', [$profile['email']])->exists()) {
            throw new ActionRefused("Já existe uma conta com o e-mail {$profile['email']}. Entre com o GitHub ou com a senha.", route('login'));
        }

        try {
            $password = $this->registration->registerWithGithub(
                [...$profile, 'name' => mb_substr($profile['name'] ?? $profile['login'], 0, 120)],
                $session,
            );
        } catch (ValidationException) {
            // A corrida com outro cadastro do mesmo e-mail: a mesma resposta de quando o e-mail já tinha conta.
            throw new ActionRefused("Já existe uma conta com o e-mail {$profile['email']}. Entre com o GitHub ou com a senha.", route('login'));
        }

        return [Auth::user(), $password];
    }
}
