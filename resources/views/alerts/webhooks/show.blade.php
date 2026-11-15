@extends('layouts.app')

@section('title', $channel->appName().' · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $field = 'min-h-11 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent';
    $copy = 'min-h-9 shrink-0 rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-accent';
    $tz = $channel->timezone ?: 'America/Sao_Paulo';
    $pill = match (true) {
        $channel->status === 'pending' => ['falta comprovar o domínio', 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
        $channel->status !== 'active' => ['envio parado', 'border-red-900 bg-red-950/50 text-red-300', 'bg-red-400'],
        $channel->isPaused() => ['pausado', 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
        default => ['ativo', 'border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
    };
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div>
            <p class="mb-1 text-xs text-ink-400">
                <a href="{{ route('alerts.index') }}" class="hover:text-ink-200">Alertas</a> /
                <a href="{{ route('alerts.notifiers') }}" class="hover:text-ink-200">Notificadores</a> / Webhook
            </p>
            <div class="flex items-center gap-3.5">
                <x-brand-icon name="webhook" class="size-11" />
                <h1 class="min-w-0 text-xl font-semibold break-words text-white">{{ $channel->appName() }}</h1>
            </div>
        </div>

        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
            <div class="flex min-w-0 flex-[1_1_300px] items-center gap-3.5">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-semibold break-all text-white">
                        {{ $channel->label() }}
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-normal break-normal {{ $pill[1] }}"><span class="size-1.5 rounded-full {{ $pill[2] }}"></span>{{ $pill[0] }}</span>
                    </p>
                    <p class="text-sm text-ink-400">
                        Cadastrado em {{ $channel->created_at->timezone($tz)->format('d/m/Y') }}
                        @if ($channel->last_success_at) · último envio {{ $channel->last_success_at->timezone($tz)->format('d/m H:i') }} @endif
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($channel->status !== 'pending')
                    <form method="POST" action="{{ route('alerts.webhooks.test', $channel) }}">
                        @csrf
                        <button class="min-h-11 rounded-lg border border-accent px-4 text-sm text-amber-200 hover:bg-accent/10">Enviar teste</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('alerts.webhooks.destroy', $channel) }}" data-confirm="Excluir o {{ $channel->appName() }}? O Security Monitor para de chamar o endereço na hora. Os alertas continuam guardados em Alertas.">
                    @csrf
                    @method('DELETE')
                    <button class="min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-red-300 hover:border-red-900">Excluir</button>
                </form>
            </div>
        </section>

        @if ($secret)
            <section class="space-y-3 rounded-xl border border-accent/60 bg-accent/5 p-5">
                <div>
                    <h2 class="font-semibold text-white">Copie o segredo agora</h2>
                    <p class="text-sm text-ink-200">
                        É o valor do cabeçalho <span class="font-mono">Security-Monitor-Notification</span> e a chave da assinatura <span class="font-mono">Security-Monitor-Signature</span>.
                        Guarde no seu servidor (numa variável de ambiente, como <span class="font-mono">SECMON_WEBHOOK_SECRET</span>): depois desta página, o Security Monitor mostra só o começo e o fim.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <input id="webhook-secret" readonly value="{{ $secret }}" spellcheck="false" class="{{ $field }} min-w-0 flex-[1_1_320px] font-mono text-xs">
                    <button type="button" data-copy="#webhook-secret" class="{{ $copy }} min-h-11">Copiar</button>
                </div>
            </section>
        @endif

        @if ($ownership)
            <section class="space-y-3">
                <div>
                    <h2 class="font-semibold text-white">Comprove que o domínio é seu</h2>
                    <p class="text-sm text-ink-400">
                        Se a sua conta já comprovou o domínio em outro serviço, o Verificar aproveita. Até lá, o Security Monitor não chama o endereço, nem para o teste.
                    </p>
                </div>
                <x-domain-ownership :state="$ownership" :action="route('alerts.webhooks.verify', $channel)" effect="o envio para" field="webhook-txt" />
            </section>
        @elseif ($channel->status === 'broken')
            <div class="rounded-xl border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                <b class="text-white">O envio para este webhook parou.</b> {{ $channel->last_error }}
                Os alertas continuam guardados em Alertas; um teste que der certo põe o webhook de volta.
            </div>
        @endif

        @include('alerts.partials.channel-center')

        <section class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
            <div>
                <h2 class="text-lg font-semibold text-white">Endereço</h2>
                <p class="text-sm text-ink-400">A URL fica criptografada e a tela mostra só o final, porque ela pode levar um token. Outro domínio precisa ser comprovado de novo.</p>
            </div>
            <form method="POST" action="{{ route('alerts.webhooks.endpoint', $channel) }}" class="grid gap-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                @csrf
                @method('PUT')
                <div class="flex flex-col gap-1.5">
                    <label for="nome" class="text-sm text-ink-400">Nome</label>
                    <input id="nome" name="nome" required maxlength="60" value="{{ old('nome', $channel->name) }}" class="{{ $field }}">
                    @error('nome') <p class="text-sm text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="flex min-w-0 flex-col gap-1.5">
                    <label for="url" class="text-sm text-ink-400">URL nova (em branco: fica a atual)</label>
                    <input id="url" name="url" type="url" inputmode="url" autocomplete="off" spellcheck="false" maxlength="{{ \App\Services\Alerts\Webhook\WebhookUrl::MAX }}"
                           value="{{ old('url') }}" placeholder="https://{{ $channel->label() }}" class="{{ $field }} w-full font-mono text-xs">
                    @error('url') <p class="text-sm text-red-400">{{ $message }}</p> @enderror
                </div>
                <button class="inline-flex min-h-11 items-center justify-self-start rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400 sm:col-span-2">Salvar o endereço</button>
            </form>
        </section>

        <section class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
            <div>
                <h2 class="text-lg font-semibold text-white">Autenticação</h2>
                <p class="text-sm text-ink-400">
                    @if ($channel->signs())
                        Ligada: cada notificação leva o <span class="font-mono text-ink-200">Security-Monitor-Notification</span> e o <span class="font-mono text-ink-200">Security-Monitor-Signature</span>.
                        Segredo <span class="font-mono text-ink-200">{{ $channel->maskedSecret() }}</span>{{ $channel->webhook_secret_at ? ', de '.$channel->webhook_secret_at->timezone($tz)->format('d/m/Y \à\s H:i') : '' }}.
                    @else
                        Desligada: as notificações saem sem os cabeçalhos de autenticação. Qualquer um que souber a URL pode se passar pelo Security Monitor.
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('alerts.webhooks.secret', $channel) }}" class="space-y-3"
                  @if ($channel->signs()) data-confirm="Trocar a autenticação? O segredo atual para de valer na hora: atualize o seu servidor logo depois." data-confirm-ok="Trocar" @endif>
                @csrf
                @method('PUT')
                @include('alerts.webhooks.partials.auth-fields', ['current' => 'gerar'])
                <button class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400">{{ $channel->signs() ? 'Trocar o segredo' : 'Salvar a autenticação' }}</button>
            </form>
        </section>

        @if ($others->isNotEmpty())
            <p class="rounded-xl border border-ink-800 px-4 py-3 text-sm text-ink-400">
                Você também recebe no
                @foreach ($others as $other)
                    <a href="{{ $other->settingsUrl() }}" class="text-accent hover:underline">{{ $other->appName() }}</a>{{ $loop->remaining > 1 ? ', no' : ($loop->remaining === 1 ? ' e no' : '.') }}
                @endforeach
                Cada canal tem as próprias preferências; se um deles estiver no horário silencioso quando o alerta sair pelo outro, ele recebe o caso no resumo diário.
            </p>
        @endif

        @include('alerts.partials.channel-preferences', ['action' => route('alerts.webhooks.update', $channel)])
        @include('alerts.webhooks.partials.docs')
        @include('alerts.partials.channel-deliveries')
    </div>
@endsection
