@props(['date', 'title', 'text'])

{{-- Um registro do diário de bordo da /sobre: data, título e o que mudou. --}}
<li {{ $attributes->class('relative pl-6.5') }}>
    <span class="absolute top-1 left-0 size-2.5 rounded-full border-[1.5px] border-accent bg-ink-950"></span>
    <p class="font-mono text-[11.5px] tracking-wide text-accent">{{ $date }}</p>
    <p class="mt-1 text-[15px] font-semibold text-white">{{ $title }}</p>
    <p class="mt-0.5 text-sm leading-relaxed text-ink-400">{{ $text }}</p>
</li>
