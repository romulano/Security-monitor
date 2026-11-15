@if ($part['edited'])
    <span class="rounded bg-accent/15 px-1.5 py-0.5 text-[11px] text-accent">
        editado em {{ $part['changed_at']->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}{{ $part['changed_by'] ? ' por '.$part['changed_by'] : '' }}
    </span>
    @if ($part['default_changed'])
        <span class="rounded bg-amber-950/60 px-1.5 py-0.5 text-[11px] text-amber-300">o padrão mudou depois</span>
    @endif
@else
    <span class="rounded bg-ink-850 px-1.5 py-0.5 text-[11px] text-ink-400">padrão</span>
@endif
