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
                Com a posse comprovada como autorização, o Security Monitor navega o seu site inteiro (só leitura e devagar, inclusive o que o robots.txt esconde dos buscadores) e procura chaves de API, tokens e senhas
                esquecidos nas páginas e nos scripts. Você diz se cada uma pode ficar exposta. Só funciona em domínio com a posse comprovada pelo
                registro TXT, e a varredura se repete toda semana.
            </p>
        </div>

        @if ($rows === [])
            <section class="{{ $box }}">
                <p class="text-sm text-ink-200"><b class="text-white">Nenhum domínio comprovado na sua conta.</b> A varredura só roda em domínio cuja posse você comprovou pelo registro TXT.</p>
                <p class="text-sm text-ink-300">Comprove um domínio no Radar de domínios (é o passo 3 da entrevista) e ele aparece aqui.</p>
                <a href="{{ route('alerts.lookalikes.interview', ['passo' => 1]) }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-accent hover:underline">Comprovar um domínio no Radar</a>
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
                                <p class="text-xs text-ink-400">Posse comprovada {{ $owned->origin }}.</p>
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
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
