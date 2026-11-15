@props(['level' => null, 'size' => 'sm'])

@php
    $styles = [
        'critical' => 'border-red-800/70 bg-red-950/50 text-red-300',
        'high' => 'border-orange-800/70 bg-orange-950/50 text-orange-300',
        'medium' => 'border-sky-800/70 bg-sky-950/50 text-sky-300',
        'low' => 'border-ink-700 bg-ink-850 text-ink-400',
    ];
    $class = $styles[$level] ?? 'border-ink-800 bg-ink-900 text-ink-700';
    $pad = $size === 'xs' ? 'px-1.5 py-px text-[10px]' : 'px-2 py-0.5 text-[11px]';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded border font-medium tracking-wide uppercase $class $pad"]) }}>
    {{ $level ?: 'sem grau' }}
</span>
