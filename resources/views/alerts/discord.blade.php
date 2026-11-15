@extends('layouts.app')

@section('title', 'Notificações no Discord · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $field = 'min-h-11 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent';
    $showConnect = ! $channel || $changing || $channel->status === 'broken';
    $blurple = 'inline-flex min-h-11 items-center gap-2.5 self-start rounded-lg bg-[#5865F2] px-4 text-sm font-semibold text-white hover:brightness-110';
    $clyde = '<svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/></svg>';
    $alsoSlack = auth()->user()->slackChannel;
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div>
            <p class="mb-1 text-xs text-ink-400">
                <a href="{{ route('alerts.index') }}" class="hover:text-ink-200">Alertas</a> /
                <a href="{{ route('alerts.notifiers') }}" class="hover:text-ink-200">Notificadores</a> / Discord
            </p>
            <div class="flex items-center gap-3.5">
                <x-brand-icon name="discord" class="size-11" />
                <h1 class="text-xl font-semibold text-white">Notificações no Discord</h1>
            </div>
            @if (! $channel && ! $where)
                <p class="mt-2 text-sm text-ink-400">Escolha como receber: num canal do servidor, para a equipe toda, ou numa mensagem direta, só para você. O histórico continua em Alertas.</p>
            @endif
        </div>

        @if (session('discord_join_offer'))
            <section class="space-y-3 rounded-xl border border-accent/60 bg-accent/5 p-5" id="comunidade">
                <h2 class="font-semibold text-white">Falta um servidor em comum</h2>
                <p class="text-sm text-ink-200">
                    O Discord só deixa o bot mandar mensagem direta para quem está em algum servidor junto com ele, e você ainda não está em
                    nenhum. Entre na comunidade do Security Monitor no Discord e a gente conecta a mensagem direta na hora. Dá para silenciar a comunidade;
                    se sair dela, as mensagens param.
                </p>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('alerts.discord.connect', ['modo' => 'comunidade'] + $target) }}" class="{{ $blurple }}">{!! $clyde !!} Entrar na comunidade do Security Monitor</a>
                    <a href="{{ route('alerts.discord.connect', ['modo' => 'canal'] + $target) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400">Prefiro receber num canal</a>
                </div>
            </section>
        @endif

        @if ($channel)
            @php
                $pill = match (true) {
                    $channel->status === 'broken' => ['precisa reconectar', 'border-red-900 bg-red-950/50 text-red-300', 'bg-red-400'],
                    $channel->status === 'pending' => ['falta escolher o canal', 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
                    $channel->isPaused() => ['pausado', 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
                    default => ['ativo', 'border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
                };
            @endphp
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
                <div class="flex min-w-0 items-center gap-3.5">
                    <span class="grid size-11 shrink-0 place-items-center rounded-lg border border-ink-700 bg-ink-850 text-white" aria-hidden="true">
                        @if ($channel->method === 'dm')
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                        @else
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3L7 21"/><path d="M17 3l-2 18"/><path d="M4 8h17"/><path d="M3 16h17"/></svg>
                        @endif
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold text-white">{{ ucfirst($channel->label()) }}</p>
                        <p class="text-sm text-ink-400">
                            Conectado {{ $channel->methodLabel() }} em {{ $channel->created_at->timezone($channel->timezone)->format('d/m/Y') }}
                            @if ($channel->last_success_at) · último envio {{ $channel->last_success_at->timezone($channel->timezone)->format('d/m H:i') }} @endif
                        </p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $pill[1] }}"><span class="size-1.5 rounded-full {{ $pill[2] }}"></span>{{ $pill[0] }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($channel->status !== 'pending')
                        <form method="POST" action="{{ route('alerts.discord.test', $target) }}">
                            @csrf
                            <button class="min-h-11 rounded-lg border border-accent px-4 text-sm text-amber-200 hover:bg-accent/10">Enviar teste</button>
                        </form>
                    @endif
                    @if ($channel->method === 'channel' && $channel->status === 'active')
                        <a href="{{ route('alerts.discord', ['escolher' => 1] + $target) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Trocar canal</a>
                    @endif
                    <a href="{{ route('alerts.discord', ['trocar' => 1] + $target) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Mudar o jeito de receber</a>
                    <form method="POST" action="{{ route('alerts.discord.destroy', $target) }}" data-confirm="Desconectar o Discord? Os alertas continuam guardados em Alertas.">
                        @csrf
                        @method('DELETE')
                        <button class="min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-red-300 hover:border-red-900">Desconectar</button>
                    </form>
                </div>
            </section>

            @if ($channel->status === 'broken')
                <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                    <b class="text-white">O Discord recusou o último envio.</b> {{ $channel->last_error }} Conecte de novo abaixo: as preferências são mantidas.
                </div>
            @endif

            @if ($channel->status !== 'pending')
                @include('alerts.partials.channel-center')
            @endif
        @endif

        @if ($picking)
            <section class="space-y-4 rounded-xl border border-accent/50 bg-ink-900 p-5">
                <div>
                    <h2 class="font-semibold text-white">Em qual canal{{ $channel->discord_guild_name ? ' de '.$channel->discord_guild_name : '' }} os alertas chegam?</h2>
                    <p class="text-sm text-ink-400">Canal privado? Libere o Security Monitor em Editar canal → Permissões antes de escolher.</p>
                </div>

                @if ($guildChannels === null)
                    <p class="rounded-lg border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                        Não conseguimos ler os canais do servidor. Se o bot foi removido, adicione de novo.
                    </p>
                    @if ($available)
                        <a href="{{ route('alerts.discord.connect', ['modo' => 'canal'] + $target) }}" class="{{ $blurple }}">{!! $clyde !!} Adicionar a um servidor</a>
                    @endif
                @elseif ($guildChannels === [])
                    <p class="text-sm text-ink-200">Esse servidor não tem canais de texto que o bot consiga ver.</p>
                @else
                    <form method="POST" action="{{ route('alerts.discord.channel', $target) }}" class="space-y-3">
                        @csrf
                        <div class="grid max-h-80 gap-1.5 overflow-y-auto sm:grid-cols-2">
                            @foreach ($guildChannels as $option)
                                <label class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-ink-200 has-checked:border-accent has-checked:bg-accent/10 has-checked:text-white">
                                    <input type="radio" name="channel_id" value="{{ $option['id'] }}" @checked(old('channel_id', $channel->discord_channel_id) === $option['id']) class="size-4 accent-accent">
                                    <span class="truncate"><span class="text-ink-400">#</span>{{ $option['name'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('channel_id') <p class="text-sm text-red-400">{{ $message }}</p> @enderror
                        <button class="inline-flex min-h-11 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Usar este canal e mandar um teste</button>
                    </form>
                @endif
            </section>
        @endif

        @if ($showConnect)
            <div class="grid gap-4 md:grid-cols-2">
                <section class="flex flex-col gap-3 rounded-xl border {{ $available ? 'border-accent/60' : 'border-ink-800' }} bg-ink-900 p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-semibold text-white">Num canal do servidor</h2>
                        @if ($available) <span class="text-xs text-accent">para a equipe</span> @endif
                    </div>
                    <p class="text-sm text-ink-200">
                        O bot do Security Monitor entra no servidor com 3 permissões (ver canais, enviar mensagens e inserir links) e escreve só no canal que você escolher.
                        Precisa da permissão de gerenciar o servidor.
                    </p>
                    @if ($available)
                        <a href="{{ route('alerts.discord.connect', ['modo' => 'canal'] + $target) }}" class="mt-auto {{ $blurple }}">{!! $clyde !!} Adicionar a um servidor</a>
                    @endif
                </section>

                @unless ($where)
                <section class="flex flex-col gap-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-semibold text-white">Em mensagem direta</h2>
                        @if ($available) <span class="text-xs text-ink-400">só para você</span> @endif
                    </div>
                    <p class="text-sm text-ink-200">
                        Os alertas chegam numa conversa entre você e o bot do Security Monitor. Você autoriza com a sua conta do Discord; guardamos só o seu ID e o nome de usuário.
                    </p>
                    <p class="text-sm text-ink-400">
                        @if (\App\Services\Alerts\Discord\DiscordClient::homeGuild())
                            O Discord só permite mensagem direta entre quem está num mesmo servidor. Se você não estiver em nenhum com o bot, oferecemos a entrada na comunidade do Security Monitor.
                        @else
                            O Discord só permite mensagem direta entre quem está num mesmo servidor: o bot precisa estar em algum servidor em que você esteja.
                        @endif
                    </p>
                    @if ($available)
                        <a href="{{ route('alerts.discord.connect', ['modo' => 'dm'] + $target) }}" class="mt-auto {{ $blurple }}">{!! $clyde !!} Receber em mensagem direta</a>
                    @endif
                </section>
                @endunless

                @unless ($available)
                    <p class="rounded-lg border border-dashed border-ink-700 px-3 py-2.5 text-sm text-ink-400 md:col-span-2">
                        Indisponível: o app do Security Monitor no Discord ainda não foi configurado pelo administrador. Use o webhook abaixo.
                    </p>
                @endunless

                <section class="flex flex-col gap-3 rounded-xl border border-ink-800 bg-ink-900 p-5 md:col-span-2">
                    <h2 class="font-semibold text-white">Colar um webhook</h2>
                    <p class="text-sm text-ink-200">Não precisa do bot: o webhook escreve num canal que você já escolheu no Discord.</p>
                    <form method="POST" action="{{ route('alerts.discord.webhook', $target) }}" class="flex flex-col gap-2">
                        @csrf
                        <label for="webhook_url" class="text-sm text-ink-400">URL do webhook</label>
                        <input id="webhook_url" name="webhook_url" type="url" required autocomplete="off" spellcheck="false"
                               value="{{ old('webhook_url') }}" placeholder="https://discord.com/api/webhooks/…"
                               class="{{ $field }} w-full font-mono text-xs">
                        @error('webhook_url')
                            <p class="text-sm text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-ink-400">Só aceitamos endereços discord.com. Guardamos criptografado e mostramos só o final.</p>
                        <button class="mt-1 inline-flex min-h-11 items-center self-start rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar e enviar um teste</button>
                    </form>
                    <details class="border-t border-ink-800 pt-3">
                        <summary class="cursor-pointer text-sm text-ink-200">Como criar um webhook (3 passos)</summary>
                        <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-ink-200">
                            <li>No Discord, abra <b class="text-white">Editar canal</b> no canal que vai receber os alertas.</li>
                            <li>Em <b class="text-white">Integrações → Webhooks</b>, clique em <b class="text-white">Novo webhook</b>.</li>
                            <li>Clique em <b class="text-white">Copiar URL do webhook</b> e cole aqui.</li>
                        </ol>
                    </details>
                </section>
            </div>

            @if (! $channel)
                <a href="{{ route('alerts.notifiers') }}" class="inline-block text-sm text-ink-400 underline underline-offset-2 hover:text-ink-200">Voltar para Notificadores</a>
            @endif
        @endif

        @if ($channel)
            @if ($alsoSlack)
                <p class="rounded-xl border border-ink-800 px-4 py-3 text-sm text-ink-400">
                    Você também recebe no <a href="{{ route('alerts.slack') }}" class="text-accent hover:underline">Slack</a>. Cada canal tem as próprias
                    preferências; se um deles estiver no horário silencioso quando o alerta sair pelo outro, ele recebe o caso no resumo diário.
                </p>
            @endif

            @include('alerts.partials.channel-preferences', ['action' => route('alerts.discord.update', $target)])
            @include('alerts.partials.channel-deliveries')
        @endif
    </div>
@endsection
