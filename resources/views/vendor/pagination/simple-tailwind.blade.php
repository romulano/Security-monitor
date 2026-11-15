{{-- A paginação simples (simplePaginate/cursorPaginate) no tema escuro: sem números nem total, porque o paginador
     simples não sabe quantas páginas há. "‹ Anterior · Página N · Próxima ›". --}}
@if ($paginator->hasPages())
    @php
        $box = 'inline-flex items-center justify-center rounded-md border px-2.5 py-1.5 font-mono text-sm';
        $link = $box.' border-ink-800 bg-ink-900 text-ink-400 hover:border-accent hover:text-ink-200';
        $off = $box.' cursor-default border-ink-850 text-ink-700';
    @endphp
    <nav role="navigation" aria-label="Paginação" class="flex flex-wrap items-center justify-end gap-1">
        @if ($paginator->onFirstPage())
            <span class="{{ $off }}" aria-disabled="true">‹ Anterior</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $link }}" aria-label="Página anterior">‹ Anterior</a>
        @endif
        @if (method_exists($paginator, 'currentPage'))
            <span class="px-2 text-xs text-ink-700" aria-current="page">Página {{ number_format($paginator->currentPage(), 0, ',', '.') }}</span>
        @endif
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $link }}" aria-label="Próxima página">Próxima ›</a>
        @else
            <span class="{{ $off }}" aria-disabled="true">Próxima ›</span>
        @endif
    </nav>
@endif
