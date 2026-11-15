<?php

namespace App\Http\Middleware;

use App\Services\Monitoring\AccessEventRecorder;
use App\Services\Monitoring\PageViewRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Acessos: a página leva o token (layouts/app) e o registro é gravado
 * no terminate, depois que a resposta já saiu para o visitante. Global, e não
 * do grupo web, para contar também o 404 de endereço que nem rota tem (quem
 * varre o site atrás de /wp-admin). No mesmo terminate, o que o site recusou
 * ou que tem cara de ataque vira evento (AccessEventRecorder), de qualquer
 * método e com qualquer resposta.
 */
class RecordPageView
{
    private const TOKEN = 'page_view_token';

    private const ENABLED = 'page_view_monitoring';

    public function __construct(private PageViewRecorder $views, private AccessEventRecorder $events) {}

    public function handle(Request $request, Closure $next): Response
    {
        // A chave é lida uma vez (o cache é o banco) e vale também para o terminate.
        $enabled = $this->views->enabled();
        $token = $enabled ? $this->views->token($request) : null;
        // Nulo também é compartilhado: a página sem monitoria não leva o token de outra.
        View::share('pageViewToken', $token);
        $request->attributes->set(self::TOKEN, $token);
        $request->attributes->set(self::ENABLED, $enabled);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $enabled = (bool) $request->attributes->get(self::ENABLED);
        $token = $request->attributes->get(self::TOKEN);
        $view = $enabled && $token ? $this->views->record($token, $request, $response) : null;
        // Com a monitoria desligada, só a tentativa recusada de login e cadastro (a /privacidade promete).
        $this->events->record($request, $response, $view, $enabled);
    }
}
