<?php

namespace App\Providers;

use App\Models\CaseMerge;
use App\Models\MonitoredDomain;
use App\Models\NewsCase;
use App\Observers\PublicCaseUrls;
use App\Observers\RadarEnrollment;
use App\Services\Admin\PentestOverview;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Discovery\NativeDnsResolver;
use App\Services\Company\Visibility;
use App\Services\Pentest\PentestBanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A descoberta pelo domínio fala com o DNS por aqui (os testes trocam por um falso).
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);

        // A visibilidade de empresa lê quem a conta é uma vez por requisição (e a fila renova a cada job).
        $this->app->scoped(Visibility::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // O Radar ativado que espera o registro TXT começa quando a posse é comprovada.
        MonitoredDomain::observe(RadarEnrollment::class);

        // Cada requisição começa com a visibilidade relida (o mesmo processo pode atender várias, como nos testes).
        Event::listen(RouteMatched::class, fn () => $this->app->forgetInstance(Visibility::class));

        // O sitemap e o aviso aos buscadores (IndexNow) acompanham a matéria que entra, muda ou sai do ar,
        // e a antiga que a fusão de matérias troca pela nova (CaseMerge).
        NewsCase::observe(PublicCaseUrls::class);
        CaseMerge::observe(PublicCaseUrls::class);

        // A faixa do teste de segurança, em toda página com login (PentestBanner): a situação mais urgente da
        // conta, menos na página da própria execução. Sem PENTEST_ALLOWED, nem consulta o banco.
        View::composer('alerts.tests.partials.banner', function (ViewInstance $view) {
            $page = request()->route('execucao');
            $view->with('banner', app(PentestBanner::class)->for(request()->user(), now(), is_string($page) ? strtolower($page) : null));
        });

        // A faixa do admin (situação 10): qualquer execução ativa, de qualquer conta, em toda página do admin.
        View::composer('admin.pentest.partials.banner', fn (ViewInstance $view) => $view->with('adminBanner', app(PentestOverview::class)->banner()));

        // A API pública de leitura (/api/news/*) consulta o Elasticsearch e não tinha limite nenhum: o
        // grupo `api` só aplica o throttle se o limitador `api` existir, e ele nunca foi definido.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // A busca do feed passa o texto do visitante como query_string do Elasticsearch (aceita curinga
        // e regex). Só a busca é limitada; ver a página inicial sem termo continua livre.
        RateLimiter::for('busca', fn (Request $request) => $request->filled('q')
            ? Limit::perMinute(60)->by($request->ip())
            : Limit::none());

        // Ritmo de envio de e-mail (job SendEmail), um só para o servidor inteiro: o provedor
        // da VPS aceita poucos envios por minuto pela porta 25.
        RateLimiter::for('email', fn () => Limit::perMinute(max(1, (int) config('secmon.mail.per_minute'))));
    }
}
