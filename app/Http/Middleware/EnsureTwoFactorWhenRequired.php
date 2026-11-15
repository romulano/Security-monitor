<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A conta que precisa da 2FA e ainda não a ligou só chega à tela de configurar
 * (e ao que ela usa: confirmar que é você e sair), além do Parar e da faixa dos
 * testes de segurança. Hoje é o admin do Security Monitor com TWO_FACTOR_ADMIN_REQUIRED:
 * só no /admin, o admin sem a 2FA seguia na área do cliente, onde a conta de
 * admin roda teste de segurança sem a aprovação e troca e-mail e senha (I1 do
 * Red Team). E a conta da empresa que exige a 2FA (`require_2fa`, E1f): até
 * ligar a dela.
 */
class EnsureTwoFactorWhenRequired
{
    private const OPEN = ['account.two-factor', 'account.two-factor.*', 'account.reauth.*', 'logout', 'alerts.tests.runs.stop', 'alerts.tests.runs.status', 'alerts.tests.banner'];

    public static function missing(?User $user): bool
    {
        return self::reason($user) !== null;
    }

    /** O aviso de quem precisa ligar a 2FA (o admin do Security Monitor), ou nulo. */
    public static function reason(?User $user): ?string
    {
        if ($user === null || ! TwoFactor::available()) {
            return null;
        }

        $admin = $user->is_admin && config('secmon.two_factor.admin_required');

        if (! $admin || TwoFactor::enabled($user)) {
            return null;
        }

        return 'Para usar o admin, ligue a verificação em duas etapas. Leva um minuto; depois você volta direto para o admin.';
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (($reason = self::reason($request->user())) !== null && ! $request->routeIs(...self::OPEN)) {
            // O endereço pedido fica guardado (url.intended): ligada a 2FA, a tela oferece voltar direto para ele.
            return redirect()->guest(route('account.two-factor'))->with('warning', $reason);
        }

        return $next($request);
    }
}
