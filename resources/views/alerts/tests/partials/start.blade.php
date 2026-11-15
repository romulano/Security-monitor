{{-- O botão de começar uma autorização. --}}
@php
    $cta = 'inline-flex min-h-11 items-center rounded-lg px-5 text-sm font-semibold';
@endphp

<div class="flex flex-wrap items-center gap-3">
    <a href="{{ route('alerts.tests.authorize', ['passo' => 1]) }}" class="{{ $cta }} bg-accent text-ink-950 hover:brightness-110">Autorizar o primeiro teste</a>
    <span class="text-xs text-ink-400">7 passos · uns 5 minutos</span>
</div>
