<!DOCTYPE html>
<html lang="pt-BR" class="h-full max-sm:scroll-pt-10">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Security Monitor')</title>
    {{-- A divulgação sai em inglês (decisão do dono, 2026-11-01): a descrição e o cartão de link (Open Graph e
         Twitter, que o Product Hunt, o Slack e as redes leem) ficam em inglês; a interface continua em português.
         A página troca o título e o texto do cartão com og_title e description (a matéria usa os dela, em inglês). --}}
    @php
        $shareTitle = 'Security Monitor · Threat intel news merged into cases by AI';
        $shareDescription = 'Security news from research labs, government alerts and the specialized press, turned by AI into structured reports (CVEs, IOCs, actors, sectors, criticality), one case per incident.';
    @endphp
    <meta name="description" content="@yield('description', $shareDescription)">
    @if (request()->filled('q'))
        {{-- Cada busca é um endereço novo: fora do índice dos buscadores, que seguem os links dela até as matérias. --}}
        <meta name="robots" content="noindex,follow">
    @endif
    <link rel="icon" href="/favicon.ico?v=2" sizes="48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
    <meta property="og:site_name" content="Security Monitor">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:locale" content="en_US">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="@yield('og_title', $shareTitle)">
    <meta property="og:description" content="@yield('description', $shareDescription)">
    <meta property="og:image" content="{{ url('/img/mascote-og.png') }}">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:image:alt" content="Security Monitor mascot: a mechanical satellite">
    {{-- A imagem é quadrada (512 px): o cartão pequeno, que não corta. --}}
    <meta name="twitter:card" content="summary">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col">

<header class="sticky top-0 z-30 border-b border-ink-800 bg-ink-950/90 backdrop-blur">
    {{-- No celular, duas linhas: o mascote e os links em cima, a busca e o idioma embaixo. Numa linha só, a busca
         ficava sem largura e os links passavam da tela, e toda página rolava de lado. Do sm para cima, uma linha.
         A 1ª linha soma 40 px ao cabeçalho fixo no celular; o max-sm:scroll-pt-10 do <html> compensa, para o título
         das âncoras (scroll-mt-*) não ficar embaixo dele. Mudou a altura da linha, mude os dois. --}}
    <div class="mx-auto grid max-w-6xl grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 px-4 py-3 sm:flex sm:gap-4">
        <a href="{{ route('feed.index') }}" class="col-start-1 row-start-1 flex shrink-0 items-center gap-2 font-semibold tracking-tight text-white">
            <img src="/img/mascote-avatar.webp" alt="" width="28" height="28" class="size-7 rounded-full">
            <span class="hidden sm:inline">Security Monitor</span>
        </a>

        <form action="{{ route('feed.index') }}" method="GET" class="relative col-span-2 col-start-1 row-start-2 min-w-0 flex-1">
            <input
                data-search-input
                type="search"
                name="q"
                value="{{ $query ?? '' }}"
                placeholder="ransomware healthcare · criticity:critical · cves:CVE-2026-*"
                autocomplete="off"
                class="w-full rounded-lg border border-ink-800 bg-ink-900 py-2 pr-3 pl-3 text-base/5 text-ellipsis text-ink-200 placeholder:text-ink-700 focus:border-accent focus:bg-ink-850 sm:pr-16 sm:text-sm"
            >
            <kbd aria-hidden="true" class="pointer-events-none absolute top-1/2 right-3 hidden -translate-y-1/2 rounded border border-ink-700 px-1.5 py-0.5 font-mono text-[10px] text-ink-700 sm:block">/</kbd>
        </form>

        @php
            $isDash = request()->routeIs('feed.dashboard');
            $isFeed = request()->routeIs('feed.index', 'feed.show');
            $reportLang = \App\Support\ReportLanguage::fromRequest(request());
        @endphp
        {{-- Ao lado da busca no celular; no sm para cima, como quando ficava dentro do menu: a 8 px dos links e com a
             altura de linha do text-sm dele. --}}
        <div class="col-start-3 row-start-2 flex shrink-0 items-center rounded-md border border-ink-800 p-0.5 text-[11px] leading-[calc(1.25/0.875)] font-medium max-sm:self-stretch max-sm:items-stretch max-sm:rounded-lg sm:-mr-2"
             role="group" aria-label="Idioma do relatório" title="Idioma do relatório">
            @foreach (\App\Support\ReportLanguage::LABELS as $code => $label)
                <a href="{{ route('report-lang', $code) }}" @if ($reportLang === $code) aria-current="true" @endif
                   class="flex items-center rounded px-1.5 py-1 {{ $reportLang === $code ? 'bg-ink-850 text-white' : 'text-ink-700 hover:text-ink-200' }}">{{ $label }}</a>
            @endforeach
        </div>

        <nav class="col-span-2 col-start-2 row-start-1 flex shrink-0 items-center gap-1 justify-self-end text-sm">
            <a href="{{ route('feed.index') }}"
               class="rounded-md px-2.5 py-1.5 {{ $isFeed ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-ink-200' }}">Feed</a>
            <a href="{{ route('feed.dashboard') }}"
               class="rounded-md px-2.5 py-1.5 {{ $isDash ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-ink-200' }}">Painel</a>
            {{-- No celular o cabeçalho não comporta mais um link; lá o "Sobre" fica no rodapé. --}}
            <a href="{{ route('about') }}"
               class="hidden rounded-md px-2.5 py-1.5 sm:inline-block {{ request()->routeIs('about') ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-ink-200' }}">Sobre</a>

            <a href="{{ route('alerts.index') }}"
               class="rounded-md px-2.5 py-1.5 {{ request()->routeIs('alerts.*') ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-ink-200' }}">Alertas</a>
            <a href="{{ route('admin.dashboard') }}" class="rounded-md px-2.5 py-1.5 text-ink-400 hover:text-ink-200">Admin</a>
        </nav>
    </div>

    @include('alerts.tests.partials.banner')

    @hasSection('subheader')
        @yield('subheader')
    @endif
</header>

{{-- Páginas de tela cheia (Sobre) trazem as próprias faixas e containers. --}}
@hasSection('page')
    <main class="flex-1">
        @yield('page')
    </main>
@else
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-emerald-800/60 bg-emerald-950/40 px-4 py-2.5 text-sm text-emerald-300">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-4 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-2.5 text-sm text-red-300">
                {{ session('error') }}
            </div>
        @endif

        {{-- Um passo esperado, não um erro (a 2FA que a empresa ou o admin exigem). --}}
        @if (session('warning'))
            <div class="mb-4 rounded-lg border border-amber-800/60 bg-amber-950/30 px-4 py-2.5 text-sm text-amber-200">
                {{ session('warning') }}
            </div>
        @endif

        @yield('content')
    </main>
@endif

<footer class="border-t border-ink-800 px-4 py-5 text-center text-xs text-ink-700">
    Security Monitor · inteligência de ameaças · dados coletados de feeds públicos ·
    <a href="{{ route('about') }}" class="hover:text-ink-400">Sobre</a> ·
    <a href="{{ route('terms') }}" class="hover:text-ink-400">Termos</a> ·
    <a href="{{ route('privacy') }}" class="hover:text-ink-400">Privacidade</a>
</footer>

<x-dialog />

</body>
</html>
