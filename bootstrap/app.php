<?php

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureCompanyAccount;
use App\Http\Middleware\EnsureCompanyAccountActive;
use App\Http\Middleware\EnsureCompanyAdmin;
use App\Http\Middleware\EnsureEnterpriseEnabled;
use App\Http\Middleware\EnsurePentestAccess;
use App\Http\Middleware\EnsurePentestAvailable;
use App\Http\Middleware\EnsurePentestDomain;
use App\Http\Middleware\EnsureRecentReauth;
use App\Http\Middleware\EnsureSignedLinkActive;
use App\Http\Middleware\EnsureTwoFactorAvailable;
use App\Http\Middleware\EnsureTwoFactorWhenRequired;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\KeepSessionIdle;
use App\Http\Middleware\RecordPageView;
use App\Http\Middleware\RefuseRobots;
use App\Http\Middleware\RejectOutOfRangeIds;
use App\Services\Company\CompanyEntry;
use App\Services\Company\CompanyInvites;
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
            // Ação sensível: confirmar que é você há menos de 10 minutos (senha, GitHub ou código).
            'reauth' => EnsureRecentReauth::class,
            // Verificação em duas etapas: só com TWO_FACTOR_ENABLED; senão, 404.
            'two-factor.available' => EnsureTwoFactorAvailable::class,
            // A conta que precisa da 2FA (o admin com TWO_FACTOR_ADMIN_REQUIRED) e não a ligou: só a tela de configurar.
            'two-factor.required' => EnsureTwoFactorWhenRequired::class,
            // Testes de segurança: só com PENTEST_ALLOWED; senão, 404.
            'pentest.available' => EnsurePentestAvailable::class,
            // Plano empresarial: só com ENTERPRISE_ENABLED; senão, 404.
            'enterprise' => EnsureEnterpriseEnabled::class,
            // Telas de quem administra a empresa; os outros levam 404.
            'company.admin' => EnsureCompanyAdmin::class,
            // Telas de toda conta da empresa (Domínios da empresa); a conta pessoal leva 404.
            'company.account' => EnsureCompanyAccount::class,
            // A conta liberada pelo admin (ou a do admin); sem a liberação, volta à entrada da área sem executar nada.
            'pentest.access' => EnsurePentestAccess::class,
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
        // Sem a chave do plano empresarial, /admin/empresas/qualquer-id é 404 antes de procurar a empresa.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureEnterpriseEnabled::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureCompanyAdmin::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureCompanyAccount::class);
        // Id da URL grande demais para o bigint: 404 logo antes do binding, que o mandaria ao Postgres (22003, era 500).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RejectOutOfRangeIds::class);
        // A área de testes some antes até do limite de requisições: sem PENTEST_ALLOWED, é
        // sempre 404, nunca um 429 que contaria que a rota existe. Sem a liberação do admin, a
        // volta à entrada também vem antes do domínio, da senha e do limite.
        // O plano empresarial também: sem a chave, ou sem ser a admin da empresa, 404 antes do limite (nunca um 429
        // que contaria que a rota existe, nem a tentativa gasta do limite de quem administra).
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsureEnterpriseEnabled::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsureCompanyAdmin::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsureCompanyAccount::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsurePentestAvailable::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsurePentestAccess::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsurePentestDomain::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsureTwoFactorAvailable::class);

        // Cada área tem o seu login; quem já entrou cai na sua própria área.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->is_admin
            ? route('admin.dashboard')
            : route('account.show'));

        // Globais, para valer também no 404 de rota que não existe: a CSP e o registro de cada
        // página aberta em Admin → Acessos.
        $middleware->append([ContentSecurityPolicy::class, RecordPageView::class]);

        // A conta de empresa só entra com a empresa ativa e sem estar desativada (a sessão aberta também cai).
        $middleware->web(append: [EnsureCompanyAccountActive::class, RejectOutOfRangeIds::class]);
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
        // A senha e o código da reautenticação e o proxy das fontes nunca voltam no old(): o
        // old() fica gravado na sessão.
        $exceptions->dontFlash(['reauth_password', 'reauth_code', 'reauth_two_factor_code', 'two_factor_code', 'proxy_url']);

        // O link da admin da empresa (CompanyEntry) e o convite (CompanyInvites) com assinatura ruim, vencido, já usado
        // ou de empresa inativa: a mesma página amigável para todos os motivos, com o mesmo 404, sem dizer qual foi.
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! in_array($e->getStatusCode(), [403, 404], true) || ! $request->routeIs('company.entry', 'company.entry.complete', 'company.invite', 'company.invite.accept') || ! config('secmon.enterprise.enabled')) {
                return null;
            }

            $message = $request->routeIs('company.invite*') ? CompanyInvites::LINK_GONE : CompanyEntry::LINK_GONE;

            return response()->view('company.entry-invalid', ['message' => $message], 404);
        });

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
