{{-- Na empresa, vários monitoramentos (P6): um por aba, depois do título e da descrição (como no Radar), e o "Adicionar
     monitoramento" para quem pode criar. --}}
@if ($monitors->count() > 1)
    <div class="mt-4 flex flex-wrap items-center gap-2">
        @if ($monitors->count() > 1)
            <nav aria-label="Monitoramentos do GitHub" class="flex flex-wrap gap-1 rounded-lg border border-ink-800 p-1">
                @foreach ($monitors as $m)
                    <a href="{{ route('alerts.github', $m->urlParams()) }}" @if ($monitor?->id === $m->id) aria-current="page" @endif
                       class="inline-flex min-h-11 items-center rounded-md px-3 font-mono text-sm {{ $monitor?->id === $m->id ? 'bg-ink-800 text-white' : 'text-ink-400 hover:text-ink-200' }}">{{ $m->displayName() }}</a>
                @endforeach
            </nav>
        @endif
        @if ($canCreate)
            <a href="{{ route('alerts.github.interview', ['passo' => 1]) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400 hover:text-white">Adicionar monitoramento</a>
        @endif
    </div>
@endif
