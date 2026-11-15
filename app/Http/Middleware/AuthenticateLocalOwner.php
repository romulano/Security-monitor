<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A versão local e aberta não tem login: quem abre o site é o dono da instalação, e a aplicação já começa com ele
 * autenticado, como administrador.
 *
 * Por que continua existindo uma conta: tudo o que o Security Monitor guarda (alertas, Radares, monitoramentos,
 * domínios comprovados, execuções de teste) pertence a uma conta, e é por ela que as telas, o histórico e a auditoria
 * encontram as coisas. Então, em vez de arrancar o conceito, a instalação tem uma conta só, que nasce no primeiro
 * acesso e nunca pede senha.
 *
 * Quem expõe esta instalação na rede está abrindo o painel inteiro para quem alcançar a porta: rode-a em localhost,
 * ou atrás da sua própria autenticação.
 */
class AuthenticateLocalOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            Auth::login(User::localOwner());
        }

        return $next($request);
    }
}
