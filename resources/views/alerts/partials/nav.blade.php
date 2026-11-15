{{--
    Menu da área de alertas, logo abaixo do cabeçalho. Alertas: tudo o que saiu, das três fontes, com os filtros e a avaliação.
    Integração: o que vigiar e testar; Notícias, Radar de domínios, GitHub, as chaves expostas e os testes de segurança abrem por ela. Notificadores: Slack, Discord, Telegram e os
    webhooks, e o que cada um recebe.
--}}
@php
    $tabs = [
        ['Alertas', route('alerts.index'), request()->routeIs('alerts.index')],
        ['Integração', route('alerts.integrations'), request()->routeIs('alerts.integrations', 'alerts.news', 'alerts.lookalikes*', 'alerts.github*', 'alerts.tests*', 'alerts.keys*')],
        ['Notificadores', route('alerts.notifiers'), request()->routeIs('alerts.notifiers', 'alerts.slack', 'alerts.discord', 'alerts.telegram', 'alerts.webhooks*')],
    ];
@endphp

<nav aria-label="Alertas" class="border-t border-ink-800/70">
    <div class="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4">
        @foreach ($tabs as [$label, $url, $active])
            <a href="{{ $url }}" @if ($active) aria-current="page" @endif
               class="border-b-2 px-3 py-2.5 text-sm whitespace-nowrap {{ $active ? 'border-accent text-white' : 'border-transparent text-ink-400 hover:text-ink-200' }}">{{ $label }}</a>
        @endforeach
    </div>
</nav>
