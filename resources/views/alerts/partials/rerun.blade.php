@if (Route::has('alerts.domain.rerun') && $domain->canDiscover() && $domain->walls() !== [])
    @php($retryAt = $domain->wallsRetryAvailableAt())
    <form method="POST" action="{{ route('alerts.domain.rerun', $profile->urlParams()) }}" class="flex flex-wrap items-center gap-x-3 gap-y-2">
        @csrf
        <button @disabled($retryAt)
                class="min-h-11 rounded-lg border border-accent px-4 text-sm font-semibold text-amber-200 hover:bg-accent/10 disabled:cursor-not-allowed disabled:border-ink-700 disabled:text-ink-400 disabled:hover:bg-transparent">
            Ler de novo as páginas barradas
        </button>
        <span class="text-xs text-ink-400">
            {{ $retryAt
                ? 'Já tentamos hoje; liberado de novo em '.$retryAt->timezone('America/Sao_Paulo')->format('d/m \à\s H:i').'.'
                : 'Lê só as páginas barradas, 1 vez por dia; a varredura completa continua mensal.' }}
        </span>
    </form>
@endif
