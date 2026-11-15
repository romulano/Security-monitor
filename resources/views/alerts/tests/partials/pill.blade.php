{{--
    A pílula de uma execução: o estado vai sempre escrito, a cor só reforça. Espera $pill = [texto, tom]
    (ativo, alerta, parando, neutro, erro ou ok); opcional $pulse, o ponto que pulsa no que está rodando.
--}}
@php
    $tones = [
        'ativo' => ['border-accent/40 bg-accent/10 text-amber-200', 'bg-accent'],
        'alerta' => ['border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
        'parando' => ['border-red-900 bg-red-950/50 text-red-200', 'bg-red-300'],
        'neutro' => ['border-ink-700 bg-ink-850 text-ink-200', 'bg-ink-400'],
        'erro' => ['border-red-900 bg-red-950/40 text-red-300', 'bg-red-300'],
        'ok' => ['border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-300'],
    ];
    [$box, $dot] = $tones[$pill[1]] ?? $tones['neutro'];
@endphp
<span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $box }}">
    <span aria-hidden="true" class="size-1.5 shrink-0 rounded-full {{ $dot }} {{ ($pulse ?? false) ? 'motion-safe:animate-pulse' : '' }}"></span>{{ $pill[0] }}
</span>
