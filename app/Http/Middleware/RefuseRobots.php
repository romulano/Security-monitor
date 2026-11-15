<?php

namespace App\Http\Middleware;

use App\Support\UserAgents;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `robots.none`: os links da mensagem do alerta (/a/…) são para a pessoa clicar.
 * O Slack abre todo link do texto do fluxo de trabalho para montar a prévia:
 * abrir "Relevante" e "Não é relevante" votava pelo cliente, e cada link virava
 * um cartão a mais embaixo da mensagem. Robô declarado leva 404, sem cartão e
 * sem voto. O robots.txt não serve: o robô de prévia do Slack não o respeita.
 * Também vale para os botões do Radar (/r/…) e do GitHub (/g/…). HEAD também
 * leva 404: quem só confere o link (verificador de links, prévia) não clica,
 * e o voto do link é de uso único.
 */
class RefuseRobots
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->isMethod('HEAD') || UserAgents::isBot($request->userAgent()), 404);

        return $next($request);
    }
}
