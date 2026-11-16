{{--
    Os botões da varredura de um domínio comprovado: "Ativar e varrer" (a 1ª), "Varrer agora", "Pausar" e
    "Retomar". Espera $owned e $site (nulo: ainda não ativada). Só para quem configura o domínio.
--}}
@php
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $provable = \App\Services\Keys\KeySites::provable($owned);
@endphp
<div class="flex flex-wrap items-center gap-2">
    @if (! $provable)
        <span class="text-sm text-red-300">A posse de {{ $owned->domain }} não está mais comprovada.</span>
    @elseif ($site === null || $site->isActive())
        <form method="POST" action="{{ route('alerts.keys.scan', ['dominio' => $owned->domain]) }}"
              @if ($site === null) data-confirm="Ativar a varredura de chaves em {{ $owned->domain }}? O Security Monitor navega o site inteiro (só leitura, devagar, inclusive o que o robots.txt esconde dos buscadores) agora e depois uma vez por semana." data-confirm-ok="Ativar e varrer" @endif>
            @csrf
            <button @disabled($site?->isBusy()) class="{{ $button }} bg-accent font-semibold text-ink-950 hover:brightness-110 disabled:opacity-50">{{ $site === null ? 'Ativar e varrer' : ($site->isBusy() ? 'Varrendo…' : 'Varrer agora') }}</button>
        </form>
    @endif
    @if ($site !== null && $provable)
        <form method="POST" action="{{ route($site->isActive() ? 'alerts.keys.pause' : 'alerts.keys.resume', ['dominio' => $owned->domain]) }}">
            @csrf
            <button class="{{ $button }} border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white">{{ $site->isActive() ? 'Pausar' : 'Retomar a varredura' }}</button>
        </form>
    @endif
</div>
