<?php

namespace App\Services\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;

/**
 * Admin → Acessos: o endereço da página e o de onde a pessoa veio, como ficam
 * gravados, sem o que vale como senha. O link assinado (confirmação de e-mail,
 * voto do alerta) abre a ação sem login por 30 dias e fica gravado por 180: dele
 * sobra só o modelo da rota. Nas outras páginas, as chaves sensíveis da query
 * (o code e o state do OAuth, token, e-mail...) ficam só com o nome: `code=`.
 * O Referer de outro site perde a query, que é dele e não diz nada a nós.
 */
final class RecordedUrl
{
    /** Comparadas sem diferenciar maiúsculas, com o nome decodificado e cada parte de `a[b]` à parte. */
    public const SENSITIVE = [
        'signature', 'expires', 'code', 'state', 'token', 'access_token', 'refresh_token', 'id_token',
        'key', 'api_key', 'apikey', 'password', 'pass', 'passwd', 'pwd', 'senha', 'secret', 'client_secret',
        'email', 'hash', 'session', 'sid', 'auth', 'jwt',
        // O convite abre o cadastro (vale como senha até ser usado).
        'convite', 'invite',
    ];

    /**
     * O caminho em que o próprio endereço é o segredo (um {id} que é a prova, não só um identificador), pelo
     * modelo da rota; a migration e o Referer não têm a rota na mão. Vazio hoje: a confirmação de e-mail, o
     * único caso, saiu com o login. Fica como ponto de extensão para o próximo link assim.
     */
    public const SECRET_PATHS = [];

    /** O endereço da página aberta: o modelo da rota assinada, sem a query, ou o caminho com a query limpa. */
    public static function path(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route && self::signed($route)) {
            return '/'.ltrim($route->uri(), '/');
        }

        return self::sanitize($request->getRequestUri());
    }

    /** O Referer: do próprio site, limpo como o caminho; de outro (ou o que nem é URL), sem query nem fragmento. */
    public static function referer(?string $referer, Request $request): ?string
    {
        $referer = trim((string) $referer);

        if ($referer === '') {
            return null;
        }

        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));

        if ($host !== '' && in_array($host, [strtolower($request->getHost()), strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))], true)) {
            return self::sanitize($referer);
        }

        return self::withoutUser(preg_split('/[?#]/', $referer, 2)[0]) ?: null;
    }

    /**
     * Caminho + query sem os valores sensíveis. Serve também ao endereço inteiro (o Referer gravado, na
     * migration que limpa o que já estava lá): a origem fica, sem usuário e senha.
     */
    public static function sanitize(string $uri): string
    {
        $origin = '';

        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', $uri, $match)) {
            $origin = self::withoutUser($match[0]);
            $uri = substr($uri, strlen($match[0])) ?: '/';
        }

        [$path, $query] = array_pad(explode('?', explode('#', $uri, 2)[0], 2), 2, null);

        foreach (self::SECRET_PATHS as $prefix => $template) {
            if (str_starts_with(strtolower($path), $prefix)) {
                return $origin.$template;
            }
        }

        if ($query === null) {
            return $origin.$path;
        }

        $pairs = array_map(function (string $pair): string {
            $name = explode('=', $pair, 2)[0];

            return str_contains($pair, '=') && self::sensitive($name) ? $name.'=' : $pair;
        }, explode('&', $query));

        return $origin.$path.'?'.implode('&', $pairs);
    }

    /** `%73ignature`, `CODE`, `code[]` e `user[password]` contam: o PHP lê o nome decodificado, e cada parte. */
    private static function sensitive(string $name): bool
    {
        preg_match_all('/[^\[\]]+/', strtolower(urldecode($name)), $parts);

        return array_intersect(array_map('trim', $parts[0]), self::SENSITIVE) !== [];
    }

    private static function withoutUser(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*:)?//[^/?\#@]*@#i', '$1//', $url);
    }

    private static function signed(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && in_array(explode(':', $middleware)[0], ['signed', ValidateSignature::class], true)) {
                return true;
            }
        }

        return false;
    }
}
