<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `two-factor.available`: a tela de configurar a 2FA só existe com
 * TWO_FACTOR_ENABLED. Sem ela, 404, antes do limite e da reautenticação, para
 * não dizer que a rota existe nem gastar a senha.
 */
class EnsureTwoFactorAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(TwoFactor::available(), 404);

        return $next($request);
    }
}
