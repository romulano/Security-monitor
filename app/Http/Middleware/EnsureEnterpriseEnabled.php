<?php

namespace App\Http\Middleware;

use App\Services\Company\Companies;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `enterprise`: o plano empresarial só existe com ENTERPRISE_ENABLED. Sem ela, 404, sem dizer
 * que a área existe, antes de procurar o registro da URL.
 */
class EnsureEnterpriseEnabled
{
    public function __construct(private Companies $companies) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->companies->enabled(), 404);

        return $next($request);
    }
}
