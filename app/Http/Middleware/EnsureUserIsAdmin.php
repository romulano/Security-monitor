<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            abort(403, 'Área restrita a administradores.');
        }

        // TWO_FACTOR_ADMIN_REQUIRED: sem a 2FA ligada, o admin só chega à tela de configurar.
        if (EnsureTwoFactorWhenRequired::missing($request->user())) {
            // O endereço pedido fica guardado (url.intended): ligada a 2FA, a tela oferece voltar direto para ele.
            return redirect()->guest(route('account.two-factor'))->with('warning', 'Para usar o admin, ligue a verificação em duas etapas. Leva um minuto; depois você volta direto para o admin.');
        }

        return $next($request);
    }
}
