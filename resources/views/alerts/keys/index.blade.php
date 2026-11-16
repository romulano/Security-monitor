@extends('layouts.app')

@section('title', 'Chaves expostas · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $tz = 'America/Sao_Paulo';
    $box = 'space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5';
@endphp

@section('content')
    <div class="mx-auto max-w-6xl space-y-5">
        <div class="max-w-3xl space-y-1">
            @include('alerts.partials.crumbs', ['here' => 'Chaves expostas'])
            <h1 class="text-xl font-semibold text-white">Chaves expostas</h1>
            <p class="text-sm text-ink-300">
                O Security Monitor navega o seu site inteiro (só leitura e devagar, inclusive o que o robots.txt esconde dos buscadores) e procura chaves de API, tokens e senhas
                esquecidos nas páginas e nos scripts. Você diz se cada uma pode ficar exposta. Aparecem aqui os domínios que você cadastrou no Radar, em Notícias, no webhook
                ou nos testes de segurança, mais os que adicionar abaixo. A varredura se repete toda semana.
            </p>
        </div>

        <form method="POST" action="{{ route('alerts.keys.store') }}" class="{{ $box }} max-w-3xl">
            @csrf
            <label for="dominio" class="text-[15px] font-semibold text-white">Adicionar domínio</label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <input id="dominio" type="text" name="dominio" value="{{ old('dominio') }}" placeholder="empresa.com.br"
                       autocomplete="off" spellcheck="false" maxlength="253" required
                       class="min-h-11 w-full rounded-lg border border-ink-700 bg-ink-850 px-3 font-mono text-sm text-white placeholder:text-ink-400 focus:border-accent">
                <button class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Adicionar</button>
            </div>
            <p class="text-xs text-ink-400">Sem “www” nem “https://”. Adicionar não varre: a primeira varredura começa no “Ativar e varrer” do domínio.</p>
            @error('dominio')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
        </form>

        @if ($rows === [])
            <section class="{{ $box }}">
                <p class="text-sm text-ink-200"><b class="text-white">Nenhum domínio ainda.</b> Adicione um acima, ou cadastre no Radar de domínios, em Notícias ou no webhook, e ele aparece aqui.</p>
            </section>
        @else
            <ul class="space-y-3">
                @foreach ($rows as $row)
                    @php([$owned, $site, $counts] = [$row['owned'], $row['site'], $row['counts']])
                    <li class="{{ $box }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="font-mono text-[15px] font-semibold [overflow-wrap:anywhere] text-white">
                                        @if ($site || $counts['total'] > 0)
                                            <a href="{{ route('alerts.keys.domain', ['dominio' => $owned->domain]) }}" class="hover:text-accent">{{ $owned->domain }}</a>
                                        @else
                                            {{ $owned->domain }}
                                        @endif
                                    </h2>
                                    @include('alerts.tests.partials.pill', ['pill' => $row['state'], 'pulse' => $site?->isBusy() ?? false])
                                </div>
                                <p class="text-xs text-ink-400">Cadastrado {{ $owned->origin }}.</p>
                                @if ($counts['total'] > 0)
                                    <p class="text-sm text-ink-200">
                                        {{ $counts['total'] }} {{ $counts['total'] === 1 ? 'chave' : 'chaves' }}@if ($counts['open'] > 0): <b class="text-white">{{ $counts['open'] }} para decidir</b>@endif{{ $counts['leak'] > 0 ? ', '.$counts['leak'].($counts['leak'] === 1 ? ' que não deveria estar exposta' : ' que não deveriam estar expostas') : '' }}.
                                    </p>
                                @endif
                                @if ($site?->last_scan_at)
                                    <p class="text-xs text-ink-400">
                                        Última varredura em {{ $site->last_scan_at->timezone($tz)->format('d/m \à\s H:i') }}{{ $site->last_summary ? ' · '.$site->last_summary['pages'].' páginas e '.($site->last_summary['scripts'] ?? 0).' scripts' : '' }}{{ $site->isActive() && $site->next_scan_at ? ' · próxima em '.$site->next_scan_at->timezone($tz)->format('d/m') : '' }}.
                                        @if ($site->last_error) <span class="text-amber-200">{{ $site->last_error }}</span> @endif
                                    </p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($row['manageable'])
                                    @include('alerts.keys.partials.actions', ['owned' => $owned, 'site' => $site])
                                @endif
                                @if ($site || $counts['total'] > 0)
                                    <a href="{{ route('alerts.keys.domain', ['dominio' => $owned->domain]) }}" class="inline-flex min-h-11 items-center px-2 text-sm text-accent hover:underline">Ver as chaves</a>
                                @endif
                                @if ($row['manageable'])
                                    <form method="POST" action="{{ route('alerts.keys.destroy', ['dominio' => $owned->domain]) }}"
                                          data-confirm="Remover {{ $owned->domain }} de Chaves expostas? A varredura para e as chaves achadas nela são apagadas.{{ $owned->keys_listing === \App\Models\OwnedDomain::KEYS_ADDED ? '' : ' O domínio continua onde foi cadastrado (Radar, Notícias, webhook ou testes).' }}"
                                          data-confirm-ok="Remover" data-confirm-tone="danger">
                                        @csrf
                                        @method('DELETE')
                                        <button @disabled($site?->scan_status === 'running') class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm text-ink-400 hover:text-red-300 disabled:opacity-50">Remover</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
