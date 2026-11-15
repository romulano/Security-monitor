{{-- "Marquei como enviado" de um caminho do kit de denúncia (ou desfaz). Recebe $lookalike, $key e $at (data em que marcou, ou nulo). --}}
<form method="POST" action="{{ route('alerts.lookalikes.report', [$lookalike, $key]) }}">
    @csrf
    @if ($at)
        <button class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-emerald-800/70 bg-emerald-950/40 px-3 text-sm text-emerald-300 hover:border-emerald-500" title="Clique para desmarcar">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
            Enviado em {{ $at }}
        </button>
    @else
        <button class="inline-flex min-h-9 items-center rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-accent hover:text-white">Marquei como enviado</button>
    @endif
</form>
