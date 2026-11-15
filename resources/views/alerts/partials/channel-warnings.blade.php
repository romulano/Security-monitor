{{--
    Avisos de app com problema: o que recusou o último envio (o webhook parado pelas falhas), o Discord sem
    canal escolhido e o webhook com o domínio sem comprovar.
    Espera $channels; $receivers (quem ainda recebe) é opcional e vale os apps ativos sem pausa.
--}}
@php
    $receivers ??= $channels->filter(fn ($channel) => $channel->isActive() && ! $channel->isPaused());
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $pendingChannel = $channels->first(fn ($channel) => $channel->status === 'pending' && $channel->isDiscord());
    $pendingWebhooks = $channels->filter(fn ($channel) => $channel->status === 'pending' && $channel->isWebhook());
@endphp

@foreach ($channels->where('status', 'broken') as $brokenChannel)
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-900 bg-red-950/40 px-4 py-3">
        <p class="min-w-0 flex-[1_1_400px] text-sm text-red-200">
            <b class="text-white">{{ $brokenChannel->isWebhook() ? 'O envio para o '.$brokenChannel->appName().' parou.' : 'O '.$brokenChannel->appName().' recusou o último envio.' }}</b> {{ $brokenChannel->last_error }}
            {{ $receivers->isEmpty() ? 'Os alertas continuam guardados aqui até você reconectar.' : 'Os outros canais continuam recebendo.' }}
        </p>
        <a href="{{ $brokenChannel->settingsUrl() }}" class="{{ $button }} bg-red-300 font-semibold text-red-950">{{ $brokenChannel->isWebhook() ? 'Abrir o webhook' : 'Reconectar o '.$brokenChannel->appName() }}</a>
    </div>
@endforeach

@if ($pendingChannel)
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-800 bg-amber-950/30 px-4 py-3">
        <p class="min-w-0 flex-[1_1_400px] text-sm text-amber-100">
            <b class="text-white">Falta escolher o canal do Discord.</b> O bot já está em {{ $pendingChannel->discord_guild_name ?: 'seu servidor' }}.
        </p>
        <a href="{{ $pendingChannel->settingsUrl() }}" class="{{ $button }} bg-amber-300 font-semibold text-amber-950">Escolher o canal</a>
    </div>
@endif

@foreach ($pendingWebhooks as $pendingWebhook)
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-800 bg-amber-950/30 px-4 py-3">
        <p class="min-w-0 flex-[1_1_400px] text-sm text-amber-100">
            <b class="text-white">Falta comprovar o domínio do {{ $pendingWebhook->appName() }}.</b> Até lá, o Security Monitor não chama {{ $pendingWebhook->webhook_host }}.
        </p>
        <a href="{{ $pendingWebhook->settingsUrl() }}" class="{{ $button }} bg-amber-300 font-semibold text-amber-950">Comprovar o domínio</a>
    </div>
@endforeach
