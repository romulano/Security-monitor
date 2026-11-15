{{--
    Aviso de uso único na página que os links da mensagem abrem (alerta, Radar,
    GitHub): cada link vale um clique, e o motivo, um envio. Espera $already,
    $noteOpen e $where (onde mudar a avaliação com login).
--}}
@if ($already || ! $noteOpen)
    <p class="mx-auto mt-6 max-w-md rounded-lg border border-ink-800 bg-ink-900 px-4 py-3 text-sm text-ink-200">
        @if ($already)
            Os links da mensagem valem um clique: nada mudou agora.
        @endif
        @if (! $noteOpen)
            O motivo desta página já foi enviado.
        @endif
        Para mudar a avaliação, entre no Security Monitor, {{ $where }}.
    </p>
@endif
