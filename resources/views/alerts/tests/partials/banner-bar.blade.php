{{--
    O conteúdo da faixa do teste: uma situação por vez, com o estado sempre escrito. "Parando" é anunciado na
    hora (assertive) e "o TXT sumiu" como alerta; as outras, como status. No celular, a frase inteira é o link
    "Acompanhar", em duas linhas, e o botão diz só "Parar" (situação 11). Espera $banner (PentestBanner::for()).
--}}
@php
    $run = $banner['run'];
    // O fundo, o ponto e a borda do link de cada tom (canvas: Estados-gerais).
    $tones = [
        'ativo' => ['border-accent/30 bg-accent/10', 'bg-accent', 'border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white'],
        'alerta' => ['border-amber-800/70 bg-amber-950/50', 'bg-amber-300', 'border-amber-800 text-amber-100 hover:border-amber-500'],
        'parando' => ['border-red-900/70 bg-red-950/60', 'bg-red-300', 'border-red-800/70 text-red-200 hover:border-red-400'],
        'neutro' => ['border-ink-800 bg-ink-900', 'bg-ink-400', 'border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white'],
        'erro' => ['border-red-900/70 bg-red-950/50', 'bg-red-300', 'border-red-800/70 text-red-200 hover:border-red-400'],
    ];
    [$box, $dot, $link] = $tones[$banner['tone']] ?? $tones['neutro'];
    $show = route('alerts.tests.runs.show', ['execucao' => $run->uuid]);
    $pulse = in_array($banner['kind'], ['running', 'stopping'], true);
    $alert = $banner['kind'] === 'stopped' && $banner['live'] === 'assertive';
@endphp
<div class="border-t {{ $box }}" data-banner-kind="{{ $banner['kind'] }}">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-sm text-ink-200">
        <span aria-hidden="true" class="size-2 shrink-0 rounded-full {{ $dot }} {{ $pulse ? 'motion-safe:animate-pulse' : '' }}"></span>
        <p @if ($alert) role="alert" @else role="status" aria-live="{{ $banner['live'] }}" @endif class="min-w-0 flex-1">
            <a href="{{ $show }}" class="flex min-h-11 flex-col justify-center sm:hidden">
                <b class="text-white">{{ $banner['short'] }}</b>
                <span class="text-xs text-ink-400"><span class="inline-block max-w-full font-mono [overflow-wrap:anywhere]">{{ $run->domain }}</span> · {{ $banner['link'] }}</span>
            </a>
            <span class="hidden sm:inline">{{ \App\Services\Pentest\PentestRunView::withDomain($banner['text'], $run->domain) }}</span>
            {{-- O contador muda a cada segundo: fora do leitor de tela, que ouve uma vez pelo data-banner-announce. --}}
            <span data-banner-stale aria-hidden="true" class="block text-xs text-amber-200" hidden></span>
        </p>
        <div class="flex shrink-0 items-center gap-2">
            <a href="{{ $show }}" class="hidden min-h-11 items-center rounded-lg border px-3.5 sm:inline-flex {{ $link }}">{{ $banner['link'] }}</a>
            @if ($banner['stop'])
                @include('alerts.tests.partials.stop', ['short' => true])
            @elseif ($banner['cancel'])
                <form method="POST" action="{{ route('alerts.tests.runs.stop', ['execucao' => $run->uuid]) }}" data-confirm-ok="Cancelar o teste" data-confirm-tone="danger"
                      data-confirm-cancel="Manter o teste"
                      data-confirm="Cancelar o teste agendado em {{ $run->domain }}? Nada foi enviado ao site, e a execução não começa.">
                    @csrf
                    <button class="inline-flex min-h-11 items-center rounded-lg px-3.5 text-ink-200 hover:text-white">Cancelar</button>
                </form>
            @endif
        </div>
    </div>
</div>
