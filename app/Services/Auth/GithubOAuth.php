<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * "Entrar com o GitHub": o OAuth de quem faz login ou cadastro, e de quem
 * confirma que é você (GithubReauth). Só serve para saber quem a pessoa é (id,
 * login, nome e e-mail verificado): o token é revogado logo depois e nada é
 * lido nos repositórios dela.
 */
class GithubOAuth
{
    private const API = 'https://api.github.com';

    /** Client id e secret no .env: sem eles os botões não aparecem. */
    public static function configured(): bool
    {
        return filled(config('services.github.client_id')) && filled(config('services.github.client_secret'));
    }

    public function authorizeUrl(string $state, string $redirect): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => config('services.github.client_id'),
            'redirect_uri' => $redirect,
            // read:user = id, login e nome; user:email = a lista de e-mails e quais são verificados.
            'scope' => 'read:user user:email',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Troca o `code` do retorno pelo token de acesso; nulo se o GitHub recusou. */
    public function exchangeCode(string $code, string $redirect): ?string
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(10)->connectTimeout(5)
                ->post('https://github.com/login/oauth/access_token', [
                    'client_id' => config('services.github.client_id'),
                    'client_secret' => config('services.github.client_secret'),
                    'code' => $code,
                    'redirect_uri' => $redirect,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        $token = $response->json('access_token');

        return $response->successful() && is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Quem é a pessoa do token: o id numérico (é ele que identifica, o login
     * muda), o login, o nome e o e-mail principal verificado. Nulo se o
     * GitHub não respondeu; `email` nulo se ela não tem e-mail verificado.
     *
     * @return array{id: string, login: string, name: ?string, email: ?string}|null
     */
    public function profile(string $token): ?array
    {
        try {
            $user = $this->get('/user', $token);
            $emails = $this->get('/user/emails', $token);
        } catch (ConnectionException) {
            return null;
        }

        if (! $user->successful() || ! $user->json('id') || ! is_string($user->json('login'))) {
            return null;
        }

        $verified = $emails->successful()
            ? collect((array) $emails->json())->filter(fn ($row) => is_array($row) && ($row['verified'] ?? false) === true && is_string($row['email'] ?? null))
            : collect();
        $email = $verified->firstWhere('primary', true)['email'] ?? $verified->first()['email'] ?? null;
        $name = $user->json('name');

        return [
            'id' => (string) $user->json('id'),
            'login' => (string) $user->json('login'),
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
            'email' => $email ? mb_strtolower($email) : null,
        ];
    }

    /** Revoga o token (melhor esforço): ele só serviu para saber quem a pessoa é. */
    public function revoke(string $token): void
    {
        try {
            Http::withBasicAuth((string) config('services.github.client_id'), (string) config('services.github.client_secret'))
                ->acceptJson()->withUserAgent('Security Monitor')->timeout(10)->connectTimeout(5)
                ->send('DELETE', self::API.'/applications/'.config('services.github.client_id').'/token', ['json' => ['access_token' => $token]]);
        } catch (ConnectionException) {
            // o token expira sozinho se for esquecido; nada a fazer
        }
    }

    private function get(string $path, string $token): Response
    {
        return Http::withToken($token)->acceptJson()->withUserAgent('Security Monitor')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(10)->connectTimeout(5)->get(self::API.$path);
    }
}
