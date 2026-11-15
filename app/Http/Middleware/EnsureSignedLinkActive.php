<?php

namespace App\Http\Middleware;

use App\Models\Contracts\VisibilityRoot;
use App\Models\Contracts\VisibleThroughRoot;
use App\Services\Access\DeliveryGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Os links das mensagens (/a/, /r/, /g/; E2e, DESENHO-5.2 §2.2): a assinatura substitui o login, mas não vale para
 * sempre. Com a empresa suspensa ou desligada, a conta que recebeu desativada ou sem visão do item, o link responde
 * "link inativo" (410) sem gravar nada; a foto, só 404 (quem pede é o Slack, o Discord ou o Telegram). Vem depois do
 * `signed` e da troca do id pelo item (SubstituteBindings). Conta pessoal: como sempre.
 */
class EnsureSignedLinkActive
{
    public function __construct(private DeliveryGate $gate) {}

    public function handle(Request $request, Closure $next, string $answer = 'page'): Response
    {
        foreach ($request->route()?->parameters() ?? [] as $item) {
            if (($item instanceof VisibleThroughRoot || $item instanceof VisibilityRoot) && $this->gate->forLink($item) !== null) {
                return $answer === 'image'
                    ? response('', 404, ['X-Robots-Tag' => 'noindex'])
                    : response()->view('alerts.link-inactive', [], 410, ['X-Robots-Tag' => 'noindex']);
            }
        }

        return $next($request);
    }
}
