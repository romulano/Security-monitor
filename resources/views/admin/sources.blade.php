@extends('layouts.admin')

@section('title', 'Fontes & bots')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-sm font-semibold text-white">Fontes de crawler</h1>
            <p class="text-xs text-ink-700">Cada fonte é um bot independente, com o seu próprio intervalo.</p>
        </div>

        <form method="POST" action="{{ route('admin.bots.run') }}">
            @csrf
            <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                Rodar as fontes no horário
            </button>
        </form>
    </div>

    <details class="mb-6 rounded-lg border border-ink-800 bg-ink-900 p-4">
        <summary class="cursor-pointer text-sm font-medium text-ink-200">Adicionar fonte</summary>

        <form method="POST" action="{{ route('admin.sources.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
            @csrf
            <label class="block">
                <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Nome</span>
                <input name="name" required value="{{ old('name') }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">URL do feed (RSS/Atom)</span>
                <input name="feed_url" type="url" required value="{{ old('feed_url') }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Intervalo (min)</span>
                <input name="interval_minutes" type="number" min="15" max="1440" value="{{ old('interval_minutes', 60) }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Itens por rodada (opcional)</span>
                <input name="max_items" type="number" min="1" max="100" value="{{ old('max_items') }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm">
            </label>
            {{-- O proxy leva a senha: nunca volta para o HTML, nem pelo old(). --}}
            <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Proxy (opcional)</span>
                <input name="proxy_url" type="password" maxlength="500" autocomplete="off" spellcheck="false"
                       placeholder="socks5h://usuario:senha@host:porta"
                       class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-sm">
                <span class="mt-1 block text-[11px] text-ink-700">Só para sites que bloqueiam o IP do servidor. Aceita http, https, socks5 e socks5h.</span>
            </label>
            <div class="sm:col-span-2">
                <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">
                    Adicionar
                </button>
            </div>
        </form>
    </details>

    <div class="space-y-2">
        @foreach ($sources as $source)
            <div class="rounded-lg border p-4 {{ $source->enabled ? 'border-ink-800 bg-ink-900' : 'border-ink-900 bg-ink-950 opacity-60' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="size-2 shrink-0 rounded-full {{ $source->enabled ? 'bg-emerald-400' : 'bg-ink-700' }}"></span>
                            <h2 class="truncate text-sm font-medium text-white">{{ $source->name }}</h2>
                            <span class="rounded bg-ink-850 px-1.5 py-px font-mono text-[11px] text-ink-400">{{ $source->host }}</span>
                            @if ($source->proxy_url)
                                <span class="shrink-0 rounded bg-accent/10 px-1.5 py-px font-mono text-[11px] text-accent">via proxy {{ $source->proxyLabel() }}</span>
                            @endif
                        </div>

                        <a href="{{ $source->feed_url }}" target="_blank" rel="noopener noreferrer"
                           class="mt-1 block truncate font-mono text-[11px] text-ink-700 hover:text-accent">{{ $source->feed_url }}</a>

                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-ink-700">
                            <span>a cada {{ $source->interval_minutes }} min</span>
                            <span>{{ number_format($source->articles_count, 0, ',', '.') }} artigos</span>
                            @if ($source->last_run_at)
                                <span>última: {{ $source->last_run_at->diffForHumans() }}</span>
                            @endif
                            @if ($source->last_status)
                                <span class="{{ $source->last_status === 'ok' ? 'text-emerald-400' : 'text-red-400' }}">
                                    {{ $source->last_status }}
                                </span>
                            @endif
                            @if ($source->consecutive_failures > 0)
                                <span class="text-red-400">{{ $source->consecutive_failures }} falha(s) seguidas</span>
                            @endif
                        </div>

                        @if ($source->last_error)
                            <p class="mt-1 truncate text-[11px] text-red-400" title="{{ $source->last_error }}">{{ $source->last_error }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                        <form method="POST" action="{{ route('admin.sources.toggle', $source) }}">
                            @csrf
                            <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-400 hover:text-ink-200">
                                {{ $source->enabled ? 'Desligar' : 'Ligar' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.sources.run', $source) }}">
                            @csrf
                            <button class="rounded-md border border-accent/40 px-2.5 py-1 text-xs text-accent hover:bg-accent/10">
                                Rodar agora
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.sources.destroy', $source) }}"
                              data-confirm="Remover a fonte {{ $source->host }}? As matérias já coletadas continuam no banco.">
                            @csrf
                            @method('DELETE')
                            <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-700 hover:text-red-400">
                                Remover
                            </button>
                        </form>
                    </div>
                </div>

                <details class="mt-3">
                    <summary class="cursor-pointer text-[11px] text-ink-700 hover:text-ink-400">editar</summary>
                    <form method="POST" action="{{ route('admin.sources.update', $source) }}" class="mt-2 flex flex-wrap items-end gap-2">
                        @csrf
                        @method('PUT')
                        <label class="block">
                            <span class="mb-1 block text-[10px] tracking-widest text-ink-700 uppercase">Nome</span>
                            <input name="name" value="{{ $source->name }}"
                                   class="rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 text-sm">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-[10px] tracking-widest text-ink-700 uppercase">Intervalo</span>
                            <input name="interval_minutes" type="number" min="15" max="1440" value="{{ $source->interval_minutes }}"
                                   class="w-24 rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 text-sm">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-[10px] tracking-widest text-ink-700 uppercase">Itens</span>
                            <input name="max_items" type="number" min="1" max="100" value="{{ $source->max_items }}"
                                   class="w-20 rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 text-sm">
                        </label>
                        {{-- Sempre vazio: em branco, o proxy atual fica como está. --}}
                        <label class="block">
                            <span class="mb-1 block text-[10px] tracking-widest text-ink-700 uppercase">Proxy</span>
                            <input name="proxy_url" type="password" maxlength="500" autocomplete="off" spellcheck="false"
                                   placeholder="{{ $source->proxy_url ? 'em branco mantém o atual' : 'socks5h://usuario:senha@host:porta' }}"
                                   class="w-72 rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 font-mono text-sm">
                        </label>
                        @if ($source->proxy_url)
                            <label class="flex items-center gap-1.5 py-1.5 text-xs text-ink-400">
                                <input type="checkbox" name="remove_proxy" value="1" class="rounded border-ink-700 bg-ink-850 text-accent">
                                Remover proxy
                            </label>
                        @endif
                        <button class="rounded-lg border border-ink-800 px-3 py-1.5 text-sm text-ink-200 hover:border-accent">Salvar</button>
                    </form>
                </details>
            </div>
        @endforeach
    </div>

    <div class="mt-6">{{ $sources->links() }}</div>
@endsection
