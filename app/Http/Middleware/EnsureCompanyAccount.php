<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `company.account`: as telas da empresa que toda conta dela usa (Domínios da empresa: a admin, o responsável e o
 * membro, cada um com o que vê). A conta pessoal leva 404, antes do limite de requisições e de procurar o registro da
 * URL. A empresa ativa e a conta não desativada já são cobradas de toda conta de empresa (EnsureCompanyAccountActive).
 */
class EnsureCompanyAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->isCompanyAccount(), 404);

        return $next($request);
    }
}
