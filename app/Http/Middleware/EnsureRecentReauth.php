<?php

namespace App\Http\Middleware;

use App\Services\Auth\Reauthentication;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `reauth`: a ação sensível só passa com a reautenticação recente desta sessão
 * (ver Reauthentication). O formulário da ação pode mandar a senha junto, no
 * campo `reauth_password`, e ela é conferida aqui; sem confirmação e sem
 * senha, a resposta volta à página com o aviso nesse campo (bag `reauth`) e a
 * ação não roda. Com a 2FA na conta, falta também o código do app
 * (`reauth_two_factor_code`, no formulário ou na caixa da reautenticação). Vai nas rotas que gravam (POST, PUT, DELETE): ver uma tela
 * nunca pede confirmação, e parar algo também não.
 */
class EnsureRecentReauth
{
    public function __construct(private Reauthentication $reauth) {}

    /** `$scopeParam`: o parâmetro da rota com o domínio do aceite (`reauth:dominio`); sem ele, as ações da conta. */
    public function handle(Request $request, Closure $next, ?string $scopeParam = null): Response
    {
        $user = $request->user() ?? throw new AuthenticationException;
        $this->reauth->ensure($user, $request->session(), $scopeParam !== null ? (string) $request->route($scopeParam) : null, $request->input('reauth_password'), $request->input('reauth_two_factor_code'));

        return $next($request);
    }
}
