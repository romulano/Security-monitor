<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') · Security Monitor</title>
    <link rel="icon" href="/favicon.ico?v=2" sizes="48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col">

<header class="border-b border-ink-800 bg-ink-900">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 font-semibold text-white">
            <img src="/img/mascote-avatar.webp" alt="" width="28" height="28" class="size-7 rounded-full">
            Admin
        </a>

        <nav class="flex flex-wrap items-center gap-1 text-sm">
            @foreach ([
                ['admin.dashboard', 'Visão geral', 'admin.dashboard'],
                ['admin.collections', 'Coletas', 'admin.collections'],
                ['admin.sources', 'Fontes & bots', 'admin.sources'],
                ['admin.settings', 'Configurações', 'admin.settings'],
                ['admin.users', 'Usuários', 'admin.users'],
                // O plano empresarial só aparece com ENTERPRISE_ENABLED.
                ...(config('secmon.enterprise.enabled') ? [['admin.companies.index', 'Empresas', 'admin.companies.*']] : []),
                ['admin.runs', 'Execuções', 'admin.runs'],
                ['admin.alerts.overview', 'Alertas', 'admin.alerts.*'],
                ['admin.prompts.index', 'Prompts', 'admin.prompts.*'],
                ['admin.logbook', 'Diário de bordo', 'admin.logbook*'],
                ['admin.accesses', 'Acessos', 'admin.accesses*'],
                ['admin.emails', 'E-mails', 'admin.emails*'],
                ['admin.security', 'Segurança', 'admin.security*'],
                ['admin.pentest.overview', 'Testes ativos', 'admin.pentest.*'],
            ] as [$route, $label, $active])
                <a href="{{ route($route) }}"
                   class="rounded-md px-2.5 py-1.5 {{ request()->routeIs($active) ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-ink-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-3 text-sm">
            <a href="{{ route('feed.index') }}" class="text-ink-400 hover:text-ink-200">Ver o site ↗</a>
        </div>
    </div>
</header>

@include('admin.pentest.partials.banner')

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

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-2.5 text-sm text-red-300">
            <ul class="list-inside list-disc space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<x-dialog />

</body>
</html>
