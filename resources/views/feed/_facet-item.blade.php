@php
    $active = \App\Support\QueryTokens::has($query, $facet['field'], $bucket['key']);
    $target = \App\Support\QueryTokens::toggle($query, $facet['field'], $bucket['key']);
@endphp

<li>
    <a {{ $facetLink($target) }}
       class="flex items-baseline justify-between gap-2 rounded px-1.5 py-1 transition
              {{ $active ? 'bg-accent/10 text-accent' : 'text-ink-400 hover:bg-ink-900 hover:text-ink-200' }}">
        <span class="truncate">{{ $bucket['key'] }}</span>
        <span class="shrink-0 font-mono text-[11px] text-ink-700">{{ $bucket['count'] }}</span>
    </a>
</li>
