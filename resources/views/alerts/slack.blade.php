@extends('layouts.app')

@section('title', 'Notificações no Slack · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $field = 'min-h-11 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent';
    $showConnect = ! $channel || $changing || $channel->status === 'broken';
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div>
            <p class="mb-1 text-xs text-ink-400">
                <a href="{{ route('alerts.index') }}" class="hover:text-ink-200">Alertas</a> /
                <a href="{{ route('alerts.notifiers') }}" class="hover:text-ink-200">Notificadores</a> / Slack
            </p>
            <div class="flex items-center gap-3.5">
                <x-brand-icon name="slack" class="size-11" />
                <h1 class="text-xl font-semibold text-white">Notificações no Slack</h1>
            </div>
            @include('alerts.partials.channel-where')
            @if (! $channel)
                <p class="text-sm text-ink-400">Conecte um canal. Os alertas e o resumo diário chegam lá; o histórico continua em Alertas.</p>
            @endif
        </div>

        @if ($channel)
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
                <div class="flex min-w-0 items-center gap-3.5">
                    <span class="grid size-11 shrink-0 place-items-center rounded-lg border border-ink-700 bg-ink-850 text-white" aria-hidden="true">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3L7 21"/><path d="M17 3l-2 18"/><path d="M4 8h17"/><path d="M3 16h17"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold text-white">{{ $channel->slack_team ? $channel->slack_team.' · ' : '' }}{{ $channel->label() }}</p>
                        <p class="text-sm text-ink-400">
                            Conectado {{ $channel->isCompany() ? 'por '.$channel->user?->name : ($channel->method === 'oauth' ? 'pelo app' : 'por webhook') }} em {{ $channel->created_at->timezone($channel->timezone)->format('d/m/Y') }}
                            @if ($channel->last_success_at) · último envio {{ $channel->last_success_at->timezone($channel->timezone)->format('d/m H:i') }} @endif
                        </p>
                    </div>
                    @if ($channel->status === 'broken')
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-red-900 bg-red-950/50 px-2.5 py-0.5 text-xs text-red-300"><span class="size-1.5 rounded-full bg-red-400"></span>precisa reconectar</span>
                    @elseif ($channel->isPaused())
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-amber-800 bg-amber-950/40 px-2.5 py-0.5 text-xs text-amber-200"><span class="size-1.5 rounded-full bg-amber-300"></span>pausado</span>
                    @else
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-emerald-800 bg-emerald-950/60 px-2.5 py-0.5 text-xs text-emerald-300"><span class="size-1.5 rounded-full bg-emerald-400"></span>ativo</span>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('alerts.slack.test', $target) }}">
                        @csrf
                        <button class="min-h-11 rounded-lg border border-accent px-4 text-sm text-amber-200 hover:bg-accent/10">Enviar teste</button>
                    </form>
                    <a href="{{ route('alerts.slack', ['trocar' => 1] + $target) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Trocar canal</a>
                    <form method="POST" action="{{ route('alerts.slack.destroy', $target) }}" data-confirm="{{ $channel->isCompany() ? 'Desconectar o Slack '.$channel->label().($channel->sector ? ' do setor '.$channel->sector->name.'? Ninguém mais recebe por ele os avisos do setor '.$channel->sector->name.'.' : ' dos canais gerais da empresa? Ninguém mais recebe por ele os avisos da empresa toda.').' Os alertas continuam guardados em Alertas.' : 'Desconectar o Slack? Os alertas continuam guardados em Alertas.' }}" @if ($channel->isCompany()) data-confirm-tone="danger" data-confirm-ok="Desconectar" @endif>
                        @csrf
                        @method('DELETE')
                        <button class="min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-red-300 hover:border-red-900">Desconectar</button>
                    </form>
                </div>
            </section>

            @if ($channel->status === 'broken')
                <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                    <b class="text-white">O Slack recusou o último envio.</b> {{ $channel->last_error }} Conecte de novo abaixo: as preferências são mantidas.
                </div>
            @endif

            @include('alerts.partials.channel-center')
        @endif

        @if ($showConnect)
            <div class="grid gap-4 md:grid-cols-2">
                <section class="flex flex-col gap-3 rounded-xl border {{ $oauthAvailable ? 'border-accent/60' : 'border-ink-800' }} bg-ink-900 p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-semibold text-white">Adicionar ao Slack</h2>
                        @if ($oauthAvailable) <span class="text-xs text-accent">recomendado</span> @endif
                    </div>
                    <p class="text-sm text-ink-200">Você escolhe o canal na tela do próprio Slack e pode trocar depois. Pedimos só a permissão de postar mensagens.</p>
                    @if ($oauthAvailable && Route::has('alerts.slack.oauth'))
                        <a href="{{ route('alerts.slack.oauth', $target) }}"
                           class="inline-flex min-h-11 items-center gap-2.5 self-start rounded-lg bg-white px-4 text-sm font-semibold text-ink-950 hover:bg-ink-200">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3L7 21"/><path d="M17 3l-2 18"/><path d="M4 8h17"/><path d="M3 16h17"/></svg>
                            Adicionar ao Slack
                        </a>
                    @else
                        <p class="rounded-lg border border-dashed border-ink-700 px-3 py-2.5 text-sm text-ink-400">
                            Indisponível: o app do Security Monitor no Slack ainda não foi configurado pelo administrador. Use o webhook ao lado.
                        </p>
                    @endif
                </section>

                <section class="flex flex-col gap-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
                    <h2 class="font-semibold text-white">Colar um webhook</h2>
                    <p class="text-sm text-ink-200">Incoming Webhook (vai para um canal) ou o webhook de um fluxo de trabalho do Slack (dá para mandar na sua DM).</p>
                    <form method="POST" action="{{ route('alerts.slack.webhook', $target) }}" class="flex flex-col gap-2">
                        @csrf
                        <label for="webhook_url" class="text-sm text-ink-400">URL do webhook</label>
                        <input id="webhook_url" name="webhook_url" type="url" required autocomplete="off" spellcheck="false"
                               value="{{ old('webhook_url') }}" placeholder="https://hooks.slack.com/services/… ou /triggers/…"
                               class="{{ $field }} w-full font-mono text-xs">
                        @error('webhook_url')
                            <p class="text-sm text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-ink-400">Só aceitamos endereços hooks.slack.com. Guardamos criptografado e mostramos só o final.</p>
                        <button class="mt-1 inline-flex min-h-11 items-center self-start rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar e enviar um teste</button>
                    </form>
                    <details class="border-t border-ink-800 pt-3">
                        <summary class="cursor-pointer text-sm text-ink-200">Como criar um webhook (3 passos)</summary>
                        <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-ink-200">
                            <li>Em api.slack.com/apps, crie um app no seu workspace e abra Incoming Webhooks.</li>
                            <li>Ative, clique em Add New Webhook to Workspace e escolha o canal.</li>
                            <li>Copie a URL gerada e cole aqui.</li>
                        </ol>
                    </details>
                    <details id="fluxo-de-trabalho" class="border-t border-ink-800 pt-3">
                        <summary class="cursor-pointer text-sm text-ink-200">Receber na sua DM com um fluxo de trabalho</summary>
                        <ol class="mt-2 list-decimal space-y-2 pl-5 text-sm text-ink-200">
                            <li>No Slack, crie um fluxo de trabalho e escolha <b class="text-white">Começa com um webhook</b>.</li>
                            <li>
                                Em <b class="text-white">Configurar variáveis</b>, adicione a chave
                                <code class="font-mono text-white">{{ \App\Services\Alerts\Slack\WorkflowVariables::KEY }}</code>, tipo <b class="text-white">Texto</b>.
                                É a única: ela leva a notificação inteira, com os links de “relevante” e “não é relevante”.
                            </li>
                            <li>
                                Adicione a etapa <b class="text-white">Enviar uma mensagem para uma pessoa</b>, escolha você e, em <b class="text-white">Inserir uma variável</b>,
                                escolha <code class="font-mono text-white">{{ \App\Services\Alerts\Slack\WorkflowVariables::KEY }}</code>.
                            </li>
                            <li>Publique o fluxo, copie a URL do webhook (começa com <span class="font-mono">https://hooks.slack.com/triggers/</span>) e cole aqui.</li>
                        </ol>
                    </details>
                </section>
            </div>

            @if (! $channel)
                <section class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
                    <h2 class="font-semibold text-white">Depois de conectar, você escolhe</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex gap-3 rounded-lg border border-ink-800 p-4">
                            <svg class="size-5 shrink-0 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>
                            <div><p class="font-semibold text-white">Na hora, só o que é seu</p><p class="text-sm text-ink-400">Notícia que casar com a sua entrevista chega assim que a análise termina.</p></div>
                        </div>
                        <div class="flex gap-3 rounded-lg border border-ink-800 p-4">
                            <svg class="size-5 shrink-0 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <div><p class="font-semibold text-white">Resumo diário às {{ \App\Support\Settings::get('alerts.digest_default_time') }}</p><p class="text-sm text-ink-400">Tudo que entrou nas últimas 24 horas, com o que afeta você no topo.</p></div>
                        </div>
                    </div>
                    <p class="text-sm text-ink-400">Dá para ligar os dois.</p>
                </section>
                <a href="{{ route('alerts.index') }}" class="inline-block text-sm text-ink-400 underline underline-offset-2 hover:text-ink-200">Pular por enquanto (os alertas ficam guardados em Alertas)</a>
            @endif
        @endif

        @if ($channel)
            @include('alerts.partials.channel-preferences', ['action' => route('alerts.slack.update', $target)])
            @include('alerts.partials.channel-deliveries')
        @endif
    </div>
@endsection
