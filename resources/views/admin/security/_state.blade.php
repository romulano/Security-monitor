{{-- Pílula de estado: ok | aviso | critico | erro | nao_testavel | (nulo = ainda sem varredura). --}}
@php
    [$text, $classes] = match ($status ?? null) {
        'ok' => ['Em ordem', 'border-emerald-800/60 bg-emerald-950/40 text-emerald-300'],
        'aviso' => ['Atenção', 'border-amber-800/60 bg-amber-950/40 text-amber-300'],
        'critico' => ['Crítico', 'border-red-800/60 bg-red-950/40 text-red-300'],
        'erro' => ['Sem resposta', 'border-red-800/60 bg-red-950/40 text-red-300'],
        'nao_testavel' => ['Não testável', 'border-ink-800 bg-ink-850 text-ink-400'],
        default => ['Aguardando', 'border-ink-800 bg-ink-850 text-ink-400'],
    };
@endphp
<span class="inline-block rounded-full border px-2 py-px text-[11px] font-medium {{ $classes }}">{{ $text }}</span>
