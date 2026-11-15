@extends('layouts.admin')

@section('title', 'Visão geral')

@section('content')
    {{-- Estado dos bots: é o que se quer saber ao abrir o admin. --}}
    <section class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Crawler', $health['crawler'], $health['crawler'] ? 'ligado' : 'desligado'],
            ['Enriquecimento por IA', $health['enricher'], $health['enricher'] ? 'ligado' : 'desligado'],
            ['Driver ativo', $health['driver_ready'], $health['driver'].($health['driver_ready'] ? '' : ' (sem credencial)')],
            ['Elasticsearch', $health['elasticsearch'], $health['elasticsearch'] ? 'online' : 'inacessível'],
        ] as [$label, $ok, $detail])
            <div class="rounded-lg border p-4 {{ $ok ? 'border-emerald-900/60 bg-emerald-950/20' : 'border-red-900/60 bg-red-950/20' }}">
                <p class="mb-1 text-[11px] tracking-widest text-ink-700 uppercase">{{ $label }}</p>
                <p class="flex items-center gap-2 text-sm font-medium {{ $ok ? 'text-emerald-300' : 'text-red-300' }}">
                    <span class="size-2 rounded-full {{ $ok ? 'bg-emerald-400' : 'bg-red-400' }}"></span>
                    {{ $detail }}
                </p>
            </div>
        @endforeach
    </section>

    @if ($security['sites'] > 0)
        {{-- Segurança dos sites: vermelho também quando a varredura parou (o agendador some em silêncio). --}}
        @php
            $secBad = $security['stale'] || in_array($security['status'], ['critico', 'erro'], true);
            $secWarn = ! $secBad && $security['status'] === 'aviso';
        @endphp
        <a href="{{ route('admin.security') }}"
           class="mb-6 flex flex-wrap items-center justify-between gap-2 rounded-lg border p-4 {{ $secBad ? 'border-red-900/60 bg-red-950/20' : ($secWarn ? 'border-amber-900/60 bg-amber-950/20' : 'border-emerald-900/60 bg-emerald-950/20') }}">
            <div>
                <p class="mb-1 text-[11px] tracking-widest text-ink-700 uppercase">Segurança dos sites</p>
                <p class="text-sm font-medium {{ $secBad ? 'text-red-300' : ($secWarn ? 'text-amber-300' : 'text-emerald-300') }}">
                    {{ $security['sites'] }} {{ $security['sites'] === 1 ? 'site' : 'sites' }} vigiado(s)
                    @if ($security['critical']) · {{ $security['critical'] }} problema(s) crítico(s) @endif
                    @if ($security['stale']) · sem varredura há mais de {{ config('secmon.security.stale_after_hours') }} h @endif
                </p>
            </div>
            <p class="text-xs text-ink-700">última varredura: {{ $security['lastScanAt']?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? 'nunca' }}</p>
        </a>
    @endif

    <section class="mb-6 flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.bots.run') }}">
            @csrf
            <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                Rodar os bots agora
            </button>
        </form>

        <form method="POST" action="{{ route('admin.reindex') }}"
              data-confirm="Reindexar todos os casos no Elasticsearch?">
            @csrf
            <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-400 hover:text-ink-200">
                Reindexar busca
            </button>
        </form>

        <a href="{{ route('admin.collections') }}"
           class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-400 hover:text-ink-200">
            Ver últimas coletas
        </a>

        <a href="{{ route('admin.settings') }}"
           class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-400 hover:text-ink-200">
            Ligar/desligar bots
        </a>
    </section>

    <section class="mb-8 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ([
            ['Casos', $stats['cases']],
            ['Fora do feed público', $stats['hidden'], route('admin.collections', ['status' => 'fora_do_feed'])],
            ['Artigos coletados', $stats['articles']],
            ['Na fila de análise', $stats['pending']],
            ['Repetições filtradas', $stats['no_update']],
            ['Fontes ativas', $stats['sources_on'].'/'.$stats['sources_total']],
            ['Docs no índice', $stats['indexed']],
            ['Jobs na fila', $stats['queued'], route('admin.queue')],
            ['Jobs falhos', $stats['failed_jobs'], route('admin.queue', ['ver' => 'falhos'])],
        ] as $card)
            @php([$label, $value, $url] = $card + [2 => null])
            @if ($url)
                <a href="{{ $url }}" class="rounded-lg border border-ink-800 bg-ink-900 p-3 hover:border-accent">
                    <p class="mb-0.5 text-[11px] text-ink-700">{{ $label }} <span class="text-ink-400">→</span></p>
                    <p class="font-mono text-lg {{ $label === 'Jobs falhos' && $value ? 'text-red-300' : 'text-white' }}">{{ number_format($value, 0, ',', '.') }}</p>
                </a>
            @else
                <div class="rounded-lg border border-ink-800 bg-ink-900 p-3">
                    <p class="mb-0.5 text-[11px] text-ink-700">{{ $label }}</p>
                    <p class="font-mono text-lg text-white">{{ is_numeric($value) ? number_format($value, 0, ',', '.') : $value }}</p>
                </div>
            @endif
        @endforeach
    </section>

    <section class="mb-8">
        <h2 class="mb-3 text-xs font-semibold tracking-widest text-ink-700 uppercase">Casos por criticidade</h2>
        <div class="flex flex-wrap gap-2">
            @foreach (config('secmon.criticities') as $level)
                <div class="rounded-lg border border-ink-800 bg-ink-900 px-4 py-2">
                    <x-criticity :level="$level" size="xs" />
                    <p class="mt-1 font-mono text-lg text-white">{{ number_format($byCriticity[$level] ?? 0, 0, ',', '.') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-xs font-semibold tracking-widest text-ink-700 uppercase">Últimas execuções</h2>
        @include('admin._runs-table', ['runs' => $runs])
    </section>
@endsection
