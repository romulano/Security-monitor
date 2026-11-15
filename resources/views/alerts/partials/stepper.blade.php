<nav aria-label="Passos da entrevista" class="flex flex-[1_1_220px] flex-col gap-0.5">
    @foreach ($steps as $n => [$title, $sub])
        @php
            $current = $n === $step;
            $done = ! $current && $n < $profile->step_reached;
        @endphp
        <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => $n])) }}" @if ($current) aria-current="step" @endif
           class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 {{ $current ? 'bg-ink-850' : 'hover:bg-ink-900' }}">
            <span class="grid size-6 shrink-0 place-items-center rounded-full border text-xs font-semibold
                         {{ $current ? 'border-accent bg-accent text-ink-950' : ($done ? 'border-emerald-700 bg-emerald-950 text-emerald-300' : 'border-ink-700 text-ink-400') }}">
                {{ $n }}
            </span>
            <span class="flex flex-col leading-tight">
                <span class="text-sm font-semibold {{ $current ? 'text-white' : 'text-ink-200' }}">{{ $title }}</span>
                <span class="text-xs text-ink-400">{{ $current ? 'agora' : ($done ? 'concluído' : $sub) }}</span>
            </span>
        </a>
    @endforeach
</nav>
