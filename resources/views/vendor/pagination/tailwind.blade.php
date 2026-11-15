{{-- A paginação do site inteiro no tema escuro, no lugar da pagination::tailwind do Laravel (branca e em inglês).
     No celular só Anterior/Próxima e a contagem; os números a partir do sm. --}}
@if ($paginator->hasPages())
    @php
        $num = fn ($n) => number_format((int) $n, 0, ',', '.');
        $box = 'min-w-9 items-center justify-center rounded-md border px-2.5 py-1.5 font-mono text-sm';
        $link = $box.' border-ink-800 bg-ink-900 text-ink-400 hover:border-accent hover:text-ink-200';
        $off = $box.' cursor-default border-ink-850 text-ink-700';
        $on = $box.' border-accent/60 bg-ink-850 text-white';
    @endphp
    <nav role="navigation" aria-label="Paginação" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-ink-700">
            {{ $num($paginator->firstItem()) }}–{{ $num($paginator->lastItem()) }} de {{ $num($paginator->total()) }}
        </p>
        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="inline-flex {{ $off }}" aria-disabled="true">‹ Anterior</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex {{ $link }}" aria-label="Página anterior">‹ Anterior</a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="hidden px-1 text-ink-700 sm:inline" aria-hidden="true">…</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="hidden sm:inline-flex {{ $on }}" aria-current="page">{{ $num($page) }}</span>
                        @else
                            <a href="{{ $url }}" class="hidden sm:inline-flex {{ $link }}" aria-label="Página {{ $num($page) }}">{{ $num($page) }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex {{ $link }}" aria-label="Próxima página">Próxima ›</a>
            @else
                <span class="inline-flex {{ $off }}" aria-disabled="true">Próxima ›</span>
            @endif
        </div>
    </nav>
@endif
