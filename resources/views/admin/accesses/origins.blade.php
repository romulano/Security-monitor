@extends('layouts.admin')

@section('title', 'Acessos · Origens')

@section('content')
    @php
        $num = fn ($value) => number_format((int) $value, 0, ',', '.');
        // Cada quadro: o "ver mais" (?mais=), o título, a explicação, as colunas (campo => nome) e as linhas. Sem ?mais, as 10 primeiras.
        $tables = [
            ['sites', 'De que site vieram', 'O domínio do Referer, sem o próprio Security Monitor.', ['domain' => 'Site', 'views' => 'Acessos', 'ips' => 'IPs'], $referrers],
            ['entradas', 'Páginas de entrada', 'A primeira página de cada IP em cada dia.', ['page' => 'Página', 'visits' => 'Entradas'], $entries],
            ['cadastro', 'O que leva ao cadastro', 'A página que o navegador diz ter aberto antes do /cadastro.', ['page' => 'Veio de', 'views' => 'Acessos', 'ips' => 'IPs'], $toSignup],
        ];
        $defaults = ['periodo' => '24h', 'robos' => 'com', 'nossos' => 'esconder'];
        $filter = fn (array $change) => route('admin.accesses.origins', array_filter(array_merge($filters, $change), fn ($v, $k) => $v !== '' && $v !== null && ($defaults[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        $seeMore = 'mt-2 inline-block text-xs text-ink-400 hover:text-accent';
    @endphp

    @include('admin.accesses._tabs', ['route' => 'admin.accesses.origins'])
    @include('admin.accesses._filters', ['route' => 'admin.accesses.origins', 'fields' => ['ip', 'pais']])
    <p class="-mt-3 mb-6 text-xs text-ink-700"
       title="Sem robô declarado, provável robô, atacante nem os nossos, e só as respostas abaixo de 400">
        Só pessoas (os IPs que ficaram {{ \App\Services\Admin\AccessReport::HUMAN_MIN_MS / 1000 }} s ou mais numa página).
    </p>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($tables as [$key, $title, $about, $columns, $all])
            @php($rows = $mais === $key ? $all : $all->take(10))
            <section id="{{ $key }}" class="min-w-0">
                <h2 class="mb-1 text-xs font-semibold tracking-widest text-ink-700 uppercase">{{ $title }}</h2>
                <p class="mb-3 text-xs text-ink-700">{{ $about }}</p>
                <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                            <tr>
                                @foreach ($columns as $field => $name)
                                    <th @class(['px-4 py-2 font-medium', 'text-right' => in_array($field, ['views', 'ips', 'visits'], true)])>{{ $name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-800">
                            @forelse ($rows as $row)
                                <tr>
                                    @foreach ($columns as $field => $name)
                                        @if (in_array($field, ['views', 'ips', 'visits'], true))
                                            <td class="px-4 py-2 text-right font-mono text-ink-200">{{ $num($row->{$field}) }}</td>
                                        @else
                                            <td class="px-4 py-2 font-mono text-xs break-all text-ink-200">{{ $row->{$field} ?? '—' }}</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($columns) }}" class="px-4 py-4 text-sm text-ink-400">Nada nesse recorte.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($mais === $key)
                    <a href="{{ $filter(['mais' => null]) }}#{{ $key }}" class="{{ $seeMore }}">Ver menos</a>
                @elseif ($all->count() > 10)
                    <a href="{{ $filter(['mais' => $key]) }}#{{ $key }}" class="{{ $seeMore }}">Ver todos os {{ $all->count() }} ›</a>
                @endif
            </section>
        @endforeach
    </div>
@endsection
