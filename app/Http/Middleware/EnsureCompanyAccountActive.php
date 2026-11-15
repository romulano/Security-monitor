<?php

namespace App\Http\Middleware;

use App\Services\Auth\Authenticator;
use App\Services\Company\Companies;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Em toda página com login: a conta de empresa só usa o Security Monitor com a empresa ativa (chave global, chave
 * da empresa, sem suspensão) e sem ter sido desativada. Senão a sessão cai e a pessoa volta ao login com
 * o motivo. Vale para toda porta de entrada (senha, GitHub, sessão já aberta). A conta pessoal passa direto.
 */
class EnsureCompanyAccountActive
{
    public function __construct(private Companies $companies, private Authenticator $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_admin && ($reason = $this->companies->accessRefusal($user)) !== null) {
            $this->auth->logout($request->session());

            return redirect()->route('login')->with('error', $reason);
        }

        return $next($request);
    }
}
