{{--
    Filtros de situação de uma lista de alertas (Alertas, Notícias, GitHub). Espera $filters (valor → rótulo),
    $current, $counts (valor → número) e $link (fn (valor) => url). Pendentes (âmbar, a fila a avaliar),
    Relevantes (verde) e Segurados pela IA (violeta) têm cor e número; os outros, só o rótulo.
--}}
@php
    $tones = [
        'pendentes' => ['bg-accent', 'bg-accent/15 text-white ring-1 ring-accent/50', 'text-accent hover:text-white', 'bg-accent/20 text-accent'],
        'relevantes' => ['bg-emerald-400', 'bg-emerald-950/70 text-emerald-100 ring-1 ring-emerald-800/70', 'text-emerald-300 hover:text-emerald-100', 'bg-emerald-900/70 text-emerald-100'],
        'segurados-pela-ia' => ['bg-violet-400', 'bg-violet-950/70 text-violet-100 ring-1 ring-violet-700/70', 'text-violet-300 hover:text-violet-100', 'bg-violet-900/70 text-violet-100'],
    ];
@endphp

<nav aria-label="{{ $label ?? 'Filtro' }}" class="flex flex-wrap gap-1 rounded-lg border border-ink-800 p-1">
    @foreach ($filters as $value => $text)
        @php
            $tone = $tones[$value] ?? null;
            $count = $counts[$value] ?? 0;
        @endphp
        {{-- Relevantes e Não relevantes andam juntos: no celular quebram de linha como um bloco só. --}}
        @if ($value === 'relevantes')<span class="inline-flex gap-1">@endif
        <a href="{{ $link($value) }}"
           @if ($current === $value) aria-current="page" @endif
           class="inline-flex min-h-9 items-center gap-1.5 rounded-md px-3 text-sm {{ match (true) {
               $tone && $current === $value => $tone[1],
               (bool) $tone => $tone[2],
               $current === $value => 'bg-ink-800 text-white',
               default => 'text-ink-400 hover:text-ink-200',
           } }}">
            @if ($tone)<span class="size-2 rounded-full {{ $tone[0] }}" aria-hidden="true"></span>@endif
            {{ $text }}
            @if ($tone && $count)<span class="rounded px-1.5 font-mono text-[11px] {{ $tone[3] }}">{{ $count }}</span>@endif
        </a>
        @if ($value === 'nao-relevantes')</span>@endif
    @endforeach
</nav>
