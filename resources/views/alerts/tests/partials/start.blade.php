{{--
    O botão de começar uma autorização, conforme a chave global (Testes-vazio): ligada, "Autorizar o primeiro
    teste"; antes da primeira vez, "Preparar a autorização" (o rascunho fica pronto para quando ligarmos);
    pausada, desligado até o Security Monitor religar. Espera $switch (PentestSwitch::status()).
--}}
@php
    $cta = 'inline-flex min-h-11 items-center rounded-lg px-5 text-sm font-semibold';
@endphp

<div class="flex flex-wrap items-center gap-3">
    @switch($switch['state'])
        @case('on')
            <a href="{{ route('alerts.tests.authorize', ['passo' => 1]) }}" class="{{ $cta }} bg-accent text-ink-950 hover:brightness-110">Autorizar o primeiro teste</a>
            <span class="text-xs text-ink-400">7 passos · uns 5 minutos</span>
            @break
        @case('not_live')
            <a href="{{ route('alerts.tests.authorize', ['passo' => 1]) }}" class="{{ $cta }} border border-accent text-white hover:bg-accent/10">Preparar a autorização</a>
            <span class="text-xs text-ink-400">Os 7 passos ficam salvos. O “Autorizar” do último passo funciona quando ligarmos os testes.</span>
            @break
        @default
            <button type="button" disabled class="{{ $cta }} cursor-not-allowed border border-ink-700 bg-ink-850 text-ink-400">Autorizar o primeiro teste</button>
            <span class="text-xs text-ink-400">Volta a funcionar quando o Security Monitor religar os testes.</span>
    @endswitch
</div>
