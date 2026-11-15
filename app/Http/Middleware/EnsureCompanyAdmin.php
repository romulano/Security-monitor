<?php

namespace App\Http\Middleware;

use App\Enums\CompanyRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `company.admin`: as telas de quem administra a empresa. Membro, responsável do setor e conta pessoal levam 404,
 * antes de procurar o registro da URL. A empresa ativa já é cobrada para toda conta de empresa (EnsureCompanyAccountActive).
 */
class EnsureCompanyAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->companyRole() === CompanyRole::Admin, 404);

        return $next($request);
    }
}
