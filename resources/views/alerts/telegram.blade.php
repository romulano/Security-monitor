@extends('layouts.app')

@section('title', 'Notificações no Telegram · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $showConnect = ! $link && (! $channel || $changing || $channel->status === 'broken');
    $blue = 'inline-flex min-h-11 items-center gap-2.5 self-start rounded-lg bg-[#2AABEE] px-4 text-sm font-semibold text-white hover:brightness-110';
    $plane = '<svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>';
    $others = auth()->user()->notificationChannels->reject->isTelegram();
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div>
            <p class="mb-1 text-xs text-ink-400">
                <a href="{{ route('alerts.index') }}" class="hover:text-ink-200">Alertas</a> /
                <a href="{{ route('alerts.notifiers') }}" class="hover:text-ink-200">Notificadores</a> / Telegram
            </p>
            <div class="flex items-center gap-3.5">
                <x-brand-icon name="telegram" class="size-11" />
                <h1 class="text-xl font-semibold text-white">Notificações no Telegram</h1>
            </div>
            @if (! $channel && ! $where)
                <p class="mt-2 text-sm text-ink-400">Escolha como receber: numa conversa privada com o bot, só para você, ou num grupo, para a equipe toda. O histórico continua em Alertas.</p>
            @endif
        </div>

        @if ($channel)
            @php
                $pill = match (true) {
                    $channel->status === 'broken' => ['precisa reconectar', 'border-red-900 bg-red-950/50 text-red-300', 'bg-red-400'],
                    $channel->isPaused() => ['pausado', 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
                    default => ['ativo', 'border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
                };
            @endphp
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
                <div class="flex min-w-0 items-center gap-3.5">
                    <span class="grid size-11 shrink-0 place-items-center rounded-lg border border-ink-700 bg-ink-850 text-white" aria-hidden="true">
                        @if ($channel->method === 'group')
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18.5 14a6.5 6.5 0 0 1 3 6"/></svg>
                        @else
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
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
                    <form method="POST" action="{{ route('alerts.telegram.test', $target) }}">
                        @csrf
                        <button class="min-h-11 rounded-lg border border-accent px-4 text-sm text-amber-200 hover:bg-accent/10">Enviar teste</button>
                    </form>
                    <a href="{{ route('alerts.telegram', ['trocar' => 1] + $target) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Mudar a conversa</a>
                    <form method="POST" action="{{ route('alerts.telegram.destroy', $target) }}" data-confirm="Desconectar o Telegram? Os alertas continuam guardados em Alertas.">
                        @csrf
                        @method('DELETE')
                        <button class="min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-red-300 hover:border-red-900">Desconectar</button>
                    </form>
                </div>
            </section>

            @if ($channel->status === 'broken')
                <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                    <b class="text-white">O Telegram recusou o último envio.</b> {{ $channel->last_error }}
                    Conecte de novo abaixo ou, se já resolveu no Telegram, envie um teste: as preferências são mantidas.
                </div>
            @endif

            @include('alerts.partials.channel-center')
        @endif

        @if ($link)
            <section class="space-y-4 rounded-xl border border-accent/60 bg-ink-900 p-5" data-telegram-wait="{{ route('alerts.telegram.status') }}">
                <div>
                    <h2 class="font-semibold text-white">{{ $link['group'] ? 'Adicione o bot do Security Monitor ao grupo' : 'Abra a conversa com o bot do Security Monitor' }}</h2>
                    <p class="text-sm text-ink-400">Esta página atualiza sozinha quando o Telegram confirmar.</p>
                </div>
                <ol class="list-decimal space-y-1 pl-5 text-sm text-ink-200">
                    @if ($link['group'])
                        <li>Toque em <b class="text-white">Escolher o grupo no Telegram</b>.</li>
                        <li>Escolha o grupo que vai receber os alertas e confirme a entrada de <b class="text-white">{{ '@'.$bot }}</b>.</li>
                        <li>O bot manda uma mensagem de teste no grupo.</li>
                    @else
                        <li>Toque em <b class="text-white">Abrir no Telegram</b>.</li>
                        <li>Na conversa com <b class="text-white">{{ '@'.$bot }}</b>, toque em <b class="text-white">Iniciar</b> (ou <i>Start</i>).</li>
                        <li>O bot manda uma mensagem de teste.</li>
                    @endif
                </ol>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="{{ $blue }}">{!! $plane !!} {{ $link['group'] ? 'Escolher o grupo no Telegram' : 'Abrir no Telegram' }}</a>
                    <form method="POST" action="{{ route('alerts.telegram.cancel', $target) }}">
                        @csrf
                        @method('DELETE')
                        <button class="min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Cancelar</button>
                    </form>
                </div>
                <p class="flex items-center gap-2 text-sm text-ink-400">
                    <span class="size-2 shrink-0 animate-pulse rounded-full bg-accent" aria-hidden="true"></span>
                    Esperando o Telegram. O link vale {{ config('secmon.alerts.telegram.link_ttl_minutes') }} minutos e uma vez só.
                </p>
                <details class="border-t border-ink-800 pt-3">
                    <summary class="cursor-pointer text-sm text-ink-200">O link não abriu?</summary>
                    <p class="mt-2 text-sm text-ink-200">
                        @if ($link['group'])
                            Adicione <b class="text-white">{{ '@'.$bot }}</b> ao grupo como membro e mande no grupo:
                        @else
                            Procure <b class="text-white">{{ '@'.$bot }}</b> no Telegram e mande:
                        @endif
                    </p>
                    <p class="mt-1.5 rounded-lg border border-ink-700 bg-ink-850 px-3 py-2 font-mono text-xs break-all text-white select-all">{{ $link['group'] ? '/start@'.$bot : '/start' }} {{ $link['code'] }}</p>
                </details>
            </section>
        @endif

        @if ($showConnect)
            <div class="grid gap-4 md:grid-cols-2">
                @unless ($where)
                <section class="flex flex-col gap-3 rounded-xl border {{ $available ? 'border-accent/60' : 'border-ink-800' }} bg-ink-900 p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-semibold text-white">Numa conversa privada</h2>
                        @if ($available) <span class="text-xs text-accent">só para você</span> @endif
                    </div>
                    <p class="text-sm text-ink-200">
                        Os alertas chegam numa conversa entre você e o bot do Security Monitor. Guardamos só o ID da conversa e o seu nome de usuário no Telegram.
                    </p>
                    @if ($available)
                        <form method="POST" action="{{ route('alerts.telegram.connect', $target) }}" class="mt-auto">
                            @csrf
                            <input type="hidden" name="modo" value="privado">
                            <button class="{{ $blue }}">{!! $plane !!} Receber numa conversa privada</button>
                        </form>
                    @endif
                </section>
                @endunless

                <section @class(['flex flex-col gap-3 rounded-xl border border-ink-800 bg-ink-900 p-5', 'md:col-span-2' => $where])>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-semibold text-white">Num grupo</h2>
                        @if ($available) <span class="text-xs text-ink-400">para a equipe</span> @endif
                    </div>
                    <p class="text-sm text-ink-200">
                        O bot entra no grupo como membro comum e só escreve os alertas. Ele não lê as conversas: o Telegram só entrega a ele os comandos com o nome dele.
                    </p>
                    <p class="text-sm text-ink-400">Precisa poder adicionar membros ao grupo.</p>
                    @if ($available)
                        <form method="POST" action="{{ route('alerts.telegram.connect', $target) }}" class="mt-auto">
                            @csrf
                            <input type="hidden" name="modo" value="grupo">
                            <button class="{{ $blue }}">{!! $plane !!} Receber num grupo</button>
                        </form>
                    @endif
                </section>

                @unless ($available)
                    <p class="rounded-lg border border-dashed border-ink-700 px-3 py-2.5 text-sm text-ink-400 md:col-span-2">
                        Indisponível: o bot do Security Monitor no Telegram ainda não foi configurado pelo administrador.
                    </p>
                @endunless
            </div>

            @if (! $channel)
                <a href="{{ route('alerts.notifiers') }}" class="inline-block text-sm text-ink-400 underline underline-offset-2 hover:text-ink-200">Voltar para Notificadores</a>
            @endif
        @endif

        @if ($channel)
            @if ($others->isNotEmpty())
                <p class="rounded-xl border border-ink-800 px-4 py-3 text-sm text-ink-400">
                    Você também recebe no
                    @foreach ($others as $other)
                        <a href="{{ $other->settingsUrl() }}" class="text-accent hover:underline">{{ $other->appName() }}</a>{{ $loop->remaining > 1 ? ', no' : ($loop->remaining === 1 ? ' e no' : '.') }}
                    @endforeach
                    Cada canal tem as próprias preferências; se um deles estiver no horário silencioso quando o alerta sair pelo outro, ele recebe o caso no resumo diário.
                </p>
            @endif

            @include('alerts.partials.channel-preferences', ['action' => route('alerts.telegram.update', $target)])
            @include('alerts.partials.channel-deliveries')
        @endif
    </div>
@endsection
