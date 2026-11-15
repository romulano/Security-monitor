<div class="mb-6 space-y-4">
    <div>
        <h1 class="text-sm font-semibold text-white">Alertas personalizados</h1>
        <p class="text-xs text-ink-400">{{ $subtitle ?? 'Entrevistas, canais e envios dos clientes.' }}</p>
    </div>
    <nav aria-label="Seções de alertas" class="flex flex-wrap gap-1 border-b border-ink-800 text-sm">
        @foreach ([
            ['admin.alerts.overview', 'Visão geral', 'admin.alerts.overview'],
            ['admin.alerts.clients', 'Clientes', 'admin.alerts.client*'],
            ['admin.alerts.deliveries', 'Entregas', 'admin.alerts.deliveries'],
            ['admin.alerts.catalog', 'Catálogo', 'admin.alerts.catalog'],
            ['admin.alerts.questions', 'Perguntas', 'admin.alerts.questions'],
            ['admin.alerts.custom', 'Respostas escritas', 'admin.alerts.custom*'],
            ['admin.alerts.domains', 'Domínios', 'admin.alerts.domains'],
        ] as [$route, $label, $active])
            @continue(! Route::has($route))
            <a href="{{ route($route) }}" @if (request()->routeIs($active)) aria-current="page" @endif
               class="-mb-px inline-flex min-h-11 items-center border-b-2 px-3 {{ request()->routeIs($active) ? 'border-accent text-white' : 'border-transparent text-ink-400 hover:text-ink-200' }}">{{ $label }}</a>
        @endforeach
    </nav>
</div>
