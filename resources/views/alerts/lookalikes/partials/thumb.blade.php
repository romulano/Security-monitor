{{-- Miniatura: o print mais recente do navegador (ou do primeiro aparelho que abriu); sem print, um quadro que diz por quê. Recebe $lookalike, $capture e $class. --}}
@php
    $device = $capture ? ($capture->devicesWithShots()[0] ?? null) : null;
    $moment = $device ? $capture->latestMoment($device) : null;
    $placeholder = [
        'pending' => ['aguardando a visita', 'M12 6v6l4 2M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z'],
        'no_site' => ['sem site', 'M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20M3 3l18 18'],
        'offline' => ['não respondeu', 'M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10'],
        'parked' => ['estacionado', 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'],
        'blank' => ['página em branco', 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6'],
        'redirects_to_you' => ['vai para o seu site', 'M5 12h14M13 6l6 6-6 6'],
        'challenge' => ['desafio anti-robô', 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
        'gone' => ['não existe mais', 'M18 6 6 18M6 6l12 12'],
    ][$capture?->state ?? $lookalike->state] ?? null;
@endphp

@if ($device)
    <img src="{{ route('alerts.lookalikes.shot', [$capture, $device, $moment === 'second' ? 'depois' : 'first']) }}" alt="Print de {{ $lookalike->displayHost() }}" loading="lazy"
         class="aspect-[4/3] w-full rounded-md border border-ink-800 bg-white object-cover object-top sm:aspect-[16/10] {{ $class ?? '' }}">
@else
    <div class="grid aspect-[4/3] w-full place-items-center rounded-md border border-dashed border-ink-700 bg-ink-950 sm:aspect-[16/10] {{ $class ?? '' }}">
        <div class="flex flex-col items-center gap-1.5 px-2 text-center text-ink-400">
            <svg class="size-4 sm:size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $placeholder[1] ?? 'M12 8v4M12 16h.01' }}"/></svg>
            <span class="text-[10px] leading-tight sm:text-[11px]">{{ $placeholder[0] ?? 'sem print' }}</span>
        </div>
    </div>
@endif
