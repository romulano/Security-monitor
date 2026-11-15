{{-- Barras empilhadas no tempo: cada linha de $rows tem `label` e um número por chave de
     $legend, de baixo para cima; a primeira cor é a mais forte. --}}
@props(['rows', 'legend'])
@php
    $colors = ['bg-accent', 'bg-ink-700'];
    $keys = array_keys($legend);
    $peak = max(1, (int) $rows->max(fn ($row) => array_sum(array_map(fn ($key) => $row->{$key}, $keys))));
@endphp
<div class="rounded-lg border border-ink-800 bg-ink-900 p-4">
    <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-ink-400">
        @foreach ($legend as $name)
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm {{ $colors[$loop->index] }}"></span> {{ $name }}</span>
        @endforeach
        <span class="ml-auto font-mono text-ink-700">até {{ number_format($peak, 0, ',', '.') }} por barra</span>
    </div>
    <div class="flex h-36 items-end gap-px" role="img" aria-label="{{ implode(', ', array_map(fn ($key, $name) => $name.': '.$rows->sum($key), $keys, $legend)) }}">
        @foreach ($rows as $row)
            <div class="flex h-full min-w-0 flex-1 flex-col-reverse" title="{{ $row->label }}: {{ implode(', ', array_map(fn ($key, $name) => $row->{$key}.' '.$name, $keys, $legend)) }}">
                @foreach ($keys as $key)
                    @if ($row->{$key})<div class="w-full {{ $colors[$loop->index] }}" style="height: {{ max(1, round($row->{$key} / $peak * 100, 1)) }}%"></div>@endif
                @endforeach
            </div>
        @endforeach
    </div>
    <div class="mt-1 flex justify-between font-mono text-[10px] text-ink-700">
        <span>{{ $rows->first()?->label }}</span>
        <span>{{ $rows->last()?->label }}</span>
    </div>
</div>
