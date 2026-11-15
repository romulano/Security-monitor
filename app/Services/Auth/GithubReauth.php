<?php

namespace App\Services\Auth;

use App\Exceptions\ActionRefused;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Confirmar que é você pelo GitHub (reautenticação), para quem entrou pelo
 * GitHub e não sabe a senha inicial. É uma porta separada do login: só vale
 * para a conta que já tem o GitHub ligado, e o id que o GitHub devolve tem de
 * ser o mesmo. Nunca liga nada: o login liga a conta pelo e-mail verificado na
 * primeira vez, a reautenticação não lê e-mail nenhum.
 */
class GithubReauth
{
    private const STATE = 'github_reauth';

    public function __construct(private GithubOAuth $github) {}

    /** App do GitHub configurado e conta com o GitHub ligado. Admin entra só por senha, então não confirma pelo GitHub. */
    public static function available(User $user): bool
    {
        return GithubOAuth::configured() && $user->hasGithub() && ! $user->is_admin;
    }

    /** O endereço da autorização no GitHub. A volta cai em `$back`, a página de onde a pessoa saiu. */
    public function start(User $user, Session $session, string $back): string
    {
        if (! self::available($user)) {
            throw new ActionRefused('Esta conta não tem um GitHub ligado. Confirme com a senha.');
        }

        $state = Str::random(40);
        $session->put(self::STATE, ['state' => $state, 'user' => $user->id, 'back' => $back, 'at' => now()->getTimestamp()]);

        return $this->github->authorizeUrl($state, route('account.reauth.github.callback'));
    }

    /**
     * A volta do GitHub: confere o state (de uso único, do mesmo usuário e com
     * até 10 minutos), descobre quem autorizou e registra a confirmação se for
     * o GitHub ligado à conta. `$code` nulo: a pessoa cancelou na tela do
     * GitHub. Devolve a página para onde voltar.
     */
    public function finish(User $user, Session $session, string $state, ?string $code): string
    {
        $expected = $session->pull(self::STATE);
        $back = is_array($expected) && is_string($expected['back'] ?? null) ? $expected['back'] : route('account.show');

        if (! is_array($expected) || ! hash_equals((string) ($expected['state'] ?? ''), $state)
            || (int) ($expected['user'] ?? 0) !== $user->id
            || now()->getTimestamp() - (int) ($expected['at'] ?? 0) > Reauthentication::MINUTES * 60) {
            throw new ActionRefused('A confirmação pelo GitHub expirou ou não começou aqui. Tente de novo.', $back);
        }

        if ($code === null) {
            throw new ActionRefused('Confirmação cancelada no GitHub.', $back);
        }

        if (! self::available($user)) {
            throw new ActionRefused('Esta conta não tem um GitHub ligado. Confirme com a senha.', $back);
        }

        $token = $this->github->exchangeCode($code, route('account.reauth.github.callback'));

        if (! $token) {
            throw new ActionRefused('O GitHub não confirmou a autorização. Tente de novo.', $back);
        }

        // O token só serve para saber quem autorizou.
        try {
            $profile = $this->github->profile($token);
        } finally {
            $this->github->revoke($token);
        }

        if (! $profile) {
            throw new ActionRefused('Não conseguimos ler o seu perfil no GitHub. Tente de novo.', $back);
        }

        // O id é o que identifica (o login muda), e só ele é comparado.
        if (! hash_equals((string) $user->github_id, $profile['id'])) {
            throw new ActionRefused("Quem autorizou foi o GitHub @{$profile['login']}, que não é o ligado a esta conta. Entre no GitHub com a conta certa e tente de novo.", $back);
        }

        Reauthentication::mark($user, 'github', $session);

        return $back;
    }
}
