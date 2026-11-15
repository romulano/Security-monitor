@extends('layouts.app')

@section('title', 'Novo webhook · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $field = 'min-h-11 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent';
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
                <h1 class="text-xl font-semibold text-white">Novo webhook</h1>
            </div>
            <p class="mt-2 text-sm text-ink-400">
                Os alertas chegam direto no sistema da sua empresa: a cada notícia relevante, o Security Monitor chama a URL que você cadastrar,
                com a notificação em JSON assinado. O histórico continua em Alertas.
            </p>
            @if ($scope)
                <p class="mt-2 text-sm text-ink-200" data-scope>
                    {{ $scope['sector'] === null
                        ? 'Este webhook é dos canais gerais: recebe os avisos do que é da empresa toda (sem setor).'
                        : "Este webhook é do setor {$scope['sector']}: recebe só os avisos do que é do setor {$scope['sector']}." }}
                </p>
            @endif
        </div>

        @if ($left === 0)
            <p class="rounded-xl border border-amber-800/70 bg-amber-950/20 px-4 py-3 text-sm text-amber-100">
                @if ($scope)
                    Não cabe outro canal {{ $scope['sector'] === null ? 'nos canais gerais' : 'no setor '.$scope['sector'] }}: o limite é de 5 canais em cada lugar e 10 webhooks na empresa. Remova um em
                    <a href="{{ route('alerts.notifiers') }}" class="underline">Notificadores</a> para cadastrar outro.
                @else
                    Você chegou ao limite de {{ \App\Services\Alerts\Webhook\WebhookConnector::max() }} webhooks. Exclua um em
                    <a href="{{ route('alerts.notifiers') }}" class="underline">Notificadores</a> para cadastrar outro.
                @endif
            </p>
        @else
            <section class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
                <h2 class="font-semibold text-white">O domínio precisa ser seu</h2>
                <p class="text-sm text-ink-200">
                        O Security Monitor só chama endereços de um domínio que a sua conta comprovou, pelo registro TXT
                        <span class="font-mono">secmon-verify=…</span> no DNS: é o que impede alguém de usar o Security Monitor contra o site de outra pessoa.
                    A prova vale para o domínio e para os subdomínios dele.
                </p>
                @if ($proven->isNotEmpty())
                    <p class="text-sm text-ink-200">
                        {{ $scope ? 'Comprovados pela empresa que valem aqui:' : 'Já comprovados na sua conta, valem na hora:' }}
                        @foreach ($proven as $domain)
                            <span class="font-mono text-white">{{ $domain }}</span>{{ $loop->remaining > 1 ? ', ' : ($loop->remaining === 1 ? ' e ' : '.') }}
                        @endforeach
                    </p>
                @elseif ($scope)
                    <p class="text-sm text-ink-400">Nenhum domínio comprovado pela empresa vale aqui ainda. Cadastre o domínio em Domínios da empresa antes.</p>
                @else
                    <p class="text-sm text-ink-400">Se o domínio ainda não foi comprovado, a próxima página mostra o registro para criar.</p>
                @endif
            </section>

            <form method="POST" action="{{ route('alerts.webhooks.store') }}" class="space-y-4 rounded-xl border border-accent/60 bg-ink-900 p-5">
                @csrf
                @if ($scope)
                    <input type="hidden" name="setor" value="{{ $scope['setor'] }}">
                @endif
                <div class="grid gap-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                    <div class="flex flex-col gap-1.5">
                        <label for="nome" class="text-sm text-ink-400">Nome</label>
                        <input id="nome" name="nome" required maxlength="60" value="{{ old('nome') }}" placeholder="Produção" class="{{ $field }}">
                        @error('nome') <p class="text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <label for="url" class="text-sm text-ink-400">URL</label>
                        <input id="url" name="url" type="url" inputmode="url" required autocomplete="off" spellcheck="false" maxlength="{{ \App\Services\Alerts\Webhook\WebhookUrl::MAX }}"
                               value="{{ old('url') }}" placeholder="https://api.empresa.com.br/secmon" class="{{ $field }} w-full font-mono text-xs">
                        @error('url') <p class="text-sm text-red-400">{{ $message }}</p> @enderror
                        <p class="text-xs text-ink-400">Só https, na porta 443, sem usuário e senha na URL. O Security Monitor não segue redirecionamentos.</p>
                    </div>
                </div>

                @include('alerts.webhooks.partials.auth-fields', ['current' => 'gerar'])

                <button class="inline-flex min-h-11 items-center rounded-lg bg-accent px-5 text-sm font-semibold text-ink-950 hover:brightness-110">Cadastrar o webhook</button>
            </form>
        @endif

        @include('alerts.webhooks.partials.docs')

        <a href="{{ route('alerts.notifiers') }}" class="inline-block text-sm text-ink-400 underline underline-offset-2 hover:text-ink-200">Voltar para Notificadores</a>
    </div>
@endsection
