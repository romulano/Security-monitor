@extends('layouts.app')

@section('title', 'Painel · Security Monitor')
@section('og_title', 'Security Monitor · Threat dashboard')
@section('description', 'What the security press is reporting right now: cases by criticality, countries, top threat actors, CVEs, malware families and victim sectors, from the cases Security Monitor tracks.')

@php
    use App\Support\QueryTokens;

    $activity = $data['activity'] ?? [];
    $peak = max(1, collect($activity)->max('count') ?? 1);
    $filterUrl = fn (string $field, string $value) => route('feed.index', ['q' => QueryTokens::token($field, $value)]);
@endphp

@section('content')
    @if (($data['error'] ?? null))
        <div class="mb-5 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-3 text-sm text-red-300">
            {{ $data['error'] }}
        </div>
    @endif

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-sm font-semibold text-white">Panorama</h1>
        <div class="flex gap-1 rounded-lg border border-ink-800 bg-ink-900 p-1 text-xs">
            @foreach ([30, 90, 180, 365] as $days)
                <a href="{{ route('feed.dashboard', ['window' => $days]) }}"
                   class="rounded px-2.5 py-1 {{ $window === $days ? 'bg-accent text-ink-950 font-medium' : 'text-ink-400 hover:text-ink-200' }}">
                    {{ $days }}d
                </a>
            @endforeach
        </div>
    </div>

    <div class="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Casos ativos', number_format($data['total_cases'] ?? 0, 0, ',', '.'), 'text-white'],
            ['Críticos', number_format($data['critical_count'] ?? 0, 0, ',', '.'), 'text-red-400'],
            ['Atualizados em 7 dias', number_format($data['updated_last_7d'] ?? 0, 0, ',', '.'), 'text-accent'],
            ['Janela analisada', $window.' dias', 'text-ink-200'],
        ] as [$label, $value, $color])
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <p class="mb-1 text-[11px] tracking-widest text-ink-700 uppercase">{{ $label }}</p>
                <p class="font-mono text-2xl {{ $color }}">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- Atividade: barras simples em CSS, sem dependência de biblioteca. --}}
    <section class="mb-8 rounded-lg border border-ink-800 bg-ink-900 p-4">
        <h2 class="mb-4 text-xs font-semibold tracking-widest text-ink-700 uppercase">Novos casos por dia</h2>

        @if ($activity)
            <div class="flex h-32 items-end gap-px" role="img" aria-label="Novos casos por dia nos últimos {{ $window }} dias">
                @foreach ($activity as $point)
                    <div class="group relative flex-1 bg-accent/70 hover:bg-accent"
                         style="height: {{ max(2, round(($point['count'] / $peak) * 100)) }}%"
                         title="{{ $point['key'] }}: {{ $point['count'] }} casos"></div>
                @endforeach
            </div>
            <div class="mt-2 flex justify-between font-mono text-[10px] text-ink-700">
                <span>{{ $activity[0]['key'] ?? '' }}</span>
                <span>pico: {{ $peak }}/dia</span>
                <span>{{ end($activity)['key'] ?? '' }}</span>
            </div>
        @else
            <p class="text-sm text-ink-700">Sem dados na janela selecionada.</p>
        @endif
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([
            ['Atores de ameaça', 'top_threat_actors', 'threat_actors'],
            ['Setores afetados', 'top_victim_sectors', 'victim_sectors'],
            ['CVEs mais citadas', 'top_cves', 'cves'],
            ['Malwares', 'top_malware', 'malware_families'],
            ['Países', 'countries', 'countries_code'],
            ['Criticidade', 'criticity', 'criticity'],
        ] as [$title, $key, $field])
            @php $buckets = array_slice($data[$key] ?? [], 0, 10); @endphp

            <section class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <h2 class="mb-3 text-xs font-semibold tracking-widest text-ink-700 uppercase">{{ $title }}</h2>

                @if ($buckets)
                    @php $localPeak = max(1, collect($buckets)->max('count')); @endphp
                    <ul class="space-y-1.5">
                        @foreach ($buckets as $bucket)
                            <li>
                                <a rel="nofollow" href="{{ $filterUrl($field, $bucket['key']) }}" class="group block">
                                    <div class="mb-0.5 flex items-baseline justify-between gap-3 text-sm">
                                        <span class="truncate text-ink-400 group-hover:text-accent">{{ $bucket['key'] }}</span>
                                        <span class="shrink-0 font-mono text-xs text-ink-700">{{ $bucket['count'] }}</span>
                                    </div>
                                    <div class="h-1 rounded-full bg-ink-850">
                                        <div class="h-1 rounded-full bg-accent/60 group-hover:bg-accent"
                                             style="width: {{ round(($bucket['count'] / $localPeak) * 100) }}%"></div>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-ink-700">Sem dados.</p>
                @endif
            </section>
        @endforeach
    </div>
@endsection
