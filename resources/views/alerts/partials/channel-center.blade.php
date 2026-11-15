{{--
    Linha de Notificadores nas páginas de cada app (Slack, Discord, Telegram): o que este app recebe
    e, se o envio estiver pausado, o aviso com o botão de retomar. Recebe $channel.
--}}
@if ($channel->isPaused())
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-800 bg-amber-950/30 px-4 py-3">
        <p class="min-w-0 flex-[1_1_360px] text-sm text-amber-100">
            <b class="text-white">Envio pausado.</b> Nada sai para o {{ $channel->appName() }}, nem o resumo diário, até você retomar.
            O teste continua funcionando, e os alertas ficam guardados no Security Monitor.
        </p>
        <form method="POST" action="{{ route('alerts.center.pause', $channel) }}">
            @csrf
            <button class="inline-flex min-h-11 items-center rounded-lg bg-amber-300 px-4 text-sm font-semibold text-amber-950 hover:brightness-110">Retomar envio</button>
        </form>
    </div>
@else
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink-800 bg-ink-900 px-4 py-3">
        <p class="min-w-0 flex-[1_1_360px] text-sm text-ink-200">
            Recebe <b class="text-white">{{ $channel->typesLabel() }}</b>.
            <a href="{{ route('alerts.notifiers') }}#canal-{{ $channel->id }}" class="text-accent hover:underline">Mudar em Notificadores</a>
        </p>
        <form method="POST" action="{{ route('alerts.center.pause', $channel) }}">
            @csrf
            <button class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400">Pausar envio</button>
        </form>
    </div>
@endif
