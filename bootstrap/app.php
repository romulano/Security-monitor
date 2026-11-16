<?php

use App\Http\Middleware\AuthenticateLocalOwner;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsurePentestDomain;
use App\Http\Middleware\EnsureSignedLinkActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\KeepSessionIdle;
use App\Http\Middleware\RefuseRobots;
use App\Http\Middleware\RejectOutOfRangeIds;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            // O domínio da rota é da conta e chegou ao passo pedido; senão, 404, antes do limite e da senha.
            'pentest.domain' => EnsurePentestDomain::class,
            // A consulta periódica lê a sessão, mas não a renova (item 6 do Blue Team).
            'session.idle' => KeepSessionIdle::class,
            // Link da mensagem do alerta: robô (a prévia do Slack) leva 404, sem cartão e sem voto.
            'robots.none' => RefuseRobots::class,
            // Link da mensagem de uma empresa suspensa, conta desativada ou sem visão do item: "link inativo" (E2e).
            'signed.active' => EnsureSignedLinkActive::class,
        ]);

        // Barrar antes de procurar o modelo da URL. Por padrão o `admin` e o `signed`
        // rodam depois do route binding: um usuário comum em /admin/casos/qualquer-coisa
        // tomava 500 do Postgres (uuid inválido), e um link assinado sem assinatura
        // dava 404 ou 403 conforme o registro existisse, entregando quais ids existem.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureUserIsAdmin::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ValidateSignature::class);
        // O "link inativo" precisa do item da URL: depois da troca do id pelo modelo, e só com a assinatura já conferida.
        $middleware->appendToPriorityList(after: SubstituteBindings::class, append: EnsureSignedLinkActive::class);
        // Id da URL grande demais para o bigint: 404 logo antes do binding, que o mandaria ao Postgres (22003, era 500).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RejectOutOfRangeIds::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsurePentestDomain::class);

        // Global, para valer também no 404 de rota que não existe.
        $middleware->append([ContentSecurityPolicy::class]);

        // A conta de empresa só entra com a empresa ativa e sem estar desativada (a sessão aberta também cai).
        // A versão local não tem login: toda requisição já começa com a conta do dono (AuthenticateLocalOwner).
        $middleware->web(prepend: [AuthenticateLocalOwner::class], append: [RejectOutOfRangeIds::class]);
        $middleware->api(append: [RejectOutOfRangeIds::class]);

        // A aplicação roda atrás do nginx do compose. Dos cabeçalhos X-Forwarded-*, só os que a
        // borda manda: o IP do visitante, o host, a porta e o https. A borda não limpa o
        // X-Forwarded-Prefix, que chegava como o visitante mandou: com ".atacante.com" ou "@atacante.com", toda
        // URL gerada na requisição (o link de confirmação do e-mail, os redirecionamentos, o
        // Canonical do security.txt) apontava para o host dele.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Limite por IP na API pública (o limitador `api` está no AppServiceProvider).
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // O proxy das fontes nunca volta no old(): o old() fica gravado na sessão.
        $exceptions->dontFlash(['proxy_url']);

        // A área dos testes com a página desatualizada (419): confirmar que é você numa aba, ou sair e entrar de novo,
        // troca o token, e o formulário aberto em outra aba chega com o antigo. No lugar da página de erro, volta à
        // página, que vem com o token novo, dizendo por quê. O Parar volta à execução (B3 do Red Team na P6a); o resto,
        // à página de onde veio, se for daqui (I1 do Red Team na P4c).
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $request->routeIs('alerts.tests.*')) {
                return null;
            }

            if ($request->routeIs('alerts.tests.runs.stop') && is_string($uuid = $request->route('execucao'))) {
                return redirect()->route('alerts.tests.runs.show', ['execucao' => $uuid])
                    ->with('error', 'A página estava desatualizada, e o pedido de parada não chegou. Aperte Parar de novo.');
            }

            $back = (string) $request->headers->get('referer');

            return redirect()->to(str_starts_with($back, $request->getSchemeAndHttpHost().'/') ? $back : route('alerts.tests'))
                ->with('error', 'A página estava desatualizada (a sessão mudou em outra aba), e o pedido não chegou. Confira e mande de novo.');
        });
    })->create();
