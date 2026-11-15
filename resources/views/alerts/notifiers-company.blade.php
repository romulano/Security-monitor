@extends('layouts.app')

@section('title', 'Notificadores · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    // Conta de empresa (E5a2, DESENHO-5.4 §2.4): os canais são da empresa, em blocos — os gerais e um por setor que a
    // conta vê. Quem configura o bloco (gerais: quem administra; setor: quem administra e quem responde por ele) liga os
    // tipos, pausa e conecta; quem só vê enxerga onde chega cada canal, sem botões.
    $switch = 'relative h-6 w-11 shrink-0 rounded-full bg-ink-700 transition peer-checked:bg-accent '
        .'peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent '
        .'after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-ink-200 after:transition '
        .'peer-checked:after:translate-x-5 peer-checked:after:bg-ink-950';
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $pill = fn ($channel) => match (true) {
        $channel->status === 'pending' => [$channel->pendingLabel(), 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
        $channel->status !== 'active' => [$channel->isWebhook() ? 'envio parado' : 'precisa reconectar', 'border-red-900 bg-red-950/50 text-red-300', 'bg-red-400'],
        $channel->isPaused() => ['pausado', 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
        default => ['conectado', 'border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
    };
    $connectLabel = ['slack' => 'Slack', 'discord' => 'Discord', 'telegram' => 'Telegram', 'webhook' => 'Webhook'];
    $connectRoute = fn (string $type, string $key) => $type === 'webhook' ? route('alerts.webhooks.create', ['setor' => $key]) : route('alerts.'.$type, ['setor' => $key]);
    $anyManaged = $managed !== [];
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div>
            <h1 class="text-xl font-semibold text-white">Notificadores</h1>
            <p class="text-sm text-ink-400">
                Os canais de aviso são da empresa {{ $company->name }}. Os gerais recebem o que é da empresa toda; os de cada setor, o que é do setor.
                Quem administra a empresa configura os gerais; quem responde por um setor, os do setor. Os alertas continuam guardados em
                <a href="{{ route('alerts.index') }}" class="text-accent hover:underline">Alertas</a>.
            </p>
            <p class="mt-2 text-xs text-ink-400">Até 5 canais gerais e 5 em cada setor. Webhooks: {{ $webhooks }} de {{ $webhookLimit }} na empresa.</p>
            <p class="mt-1 text-xs text-ink-400">{{ \App\Services\Company\CompanyChannels::PRIVATE_REFUSED }}</p>
            @unless ($blocks->contains('manages', true))
                <p class="mt-3 rounded-lg border border-ink-800 bg-ink-900 px-4 py-2.5 text-sm text-ink-200" data-viewer>
                    Você vê onde os avisos chegam. Para mudar, fale com quem administra a empresa ou com quem responde pelo setor.
                </p>
            @endunless
        </div>

        <form id="central" method="POST" action="{{ route('alerts.center.update') }}" class="space-y-5">
            @csrf
            @method('PUT')

            @foreach ($blocks as $block)
                <section class="rounded-xl border border-ink-800 bg-ink-900" aria-labelledby="bloco-{{ $block['key'] }}" data-block="{{ $block['key'] }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-ink-800 px-5 py-4">
                        <div class="min-w-0">
                            <h2 id="bloco-{{ $block['key'] }}" class="font-semibold text-white">{{ $block['title'] }}</h2>
                            <p class="text-sm text-ink-400">{{ $block['about'] }}</p>
                            @unless ($block['manages'])
                                <p class="text-xs text-ink-400">{{ $block['who'] }}</p>
                            @endunless
                        </div>
                        <span @class(['rounded-full border px-2.5 py-0.5 text-xs whitespace-nowrap',
                            'border-ink-700 text-ink-400' => $block['used'] < $block['limit'],
                            'border-amber-800 text-amber-200' => $block['used'] >= $block['limit']])>{{ $block['used'] }} de {{ $block['limit'] }} canais</span>
                    </div>

                    @forelse ($block['channels'] as $channel)
                        @php
                            [$pillLabel, $pillClass, $dotClass] = $pill($channel);
                            $mine = in_array($channel->id, $managed, true);
                        @endphp
                        <div id="canal-{{ $channel->id }}" class="border-b border-ink-800 last:border-b-0" data-channel="{{ $channel->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div class="flex min-w-0 flex-[1_1_260px] items-center gap-3.5">
                                    <x-brand-icon :name="$channel->type" class="size-10" />
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 font-semibold text-white">
                                            {{ $channel->appName() }}
                                            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-normal {{ $pillClass }}">
                                                <span class="size-1.5 rounded-full {{ $dotClass }}"></span>{{ $pillLabel }}
                                            </span>
                                        </p>
                                        <p class="truncate text-sm text-ink-200">Chegando em {{ $channel->label() }}</p>
                                        @if ($mine && $channel->isWebhook() && $channel->status === 'pending')
                                            <p class="text-xs text-amber-200">
                                                Não envia até a empresa comprovar {{ $channel->webhook_host }} em
                                                <a href="{{ route('company.domains') }}" class="underline">Domínios da empresa</a>.
                                            </p>
                                        @endif
                                        @unless ($mine)
                                            <p class="text-xs text-ink-400">
                                                Recebe: {{ collect($types)->filter(fn ($label, $type) => $channel->wants($type))->values()->join(', ', ' e ') ?: 'nada' }}
                                            </p>
                                        @endunless
                                    </div>
                                </div>
                                @if ($mine)
                                    <div class="flex flex-wrap gap-2">
                                        @unless ($channel->isWebhook() && $channel->status === 'pending')
                                        <button form="pausar-{{ $channel->id }}"
                                                class="{{ $button }} {{ $channel->isPaused() ? 'bg-accent font-semibold text-ink-950 hover:brightness-110' : 'border border-ink-700 text-ink-200 hover:border-ink-400' }}">
                                            {{ $channel->isPaused() ? 'Retomar envio' : 'Pausar envio' }}
                                        </button>
                                        @endunless
                                        <a href="{{ $channel->settingsUrl() }}" class="{{ $button }} border border-ink-800 text-ink-200 hover:border-ink-700">Preferências</a>
                                    </div>
                                @endif
                            </div>

                            @if ($mine)
                                <div class="grid gap-x-4 border-t border-ink-800/60 px-5 py-3 {{ count($types) === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
                                    @foreach ($types as $type => $label)
                                        <label class="flex min-h-11 cursor-pointer items-center justify-between gap-3">
                                            <span class="text-sm text-ink-200">{{ $label }}</span>
                                            <input type="hidden" name="tipos[{{ $channel->id }}][{{ $type }}]" value="0">
                                            <input type="checkbox" name="tipos[{{ $channel->id }}][{{ $type }}]" value="1" @checked(old('tipos.'.$channel->id.'.'.$type, $channel->wants($type)))
                                                   class="peer sr-only" aria-label="{{ $label }} no {{ $channel->appName() }} ({{ $block['title'] }})">
                                            <span aria-hidden="true" class="{{ $switch }}"></span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="px-5 py-4 text-sm text-ink-400">Nenhum canal aqui ainda.</p>
                    @endforelse

                    @if ($block['manages'])
                        <div class="flex flex-wrap items-center gap-2 border-t border-ink-800 px-5 py-3">
                            @if ($block['connect'] !== [])
                                <span class="mr-1 text-sm text-ink-400">Conectar:</span>
                            @endif
                            @forelse ($block['connect'] as $type)
                                <a href="{{ $connectRoute($type, $block['key']) }}" class="{{ $button }} gap-2 border border-ink-700 text-ink-200 hover:border-ink-400">
                                    <x-brand-icon :name="$type" class="size-5" />{{ $connectLabel[$type] }}
                                </a>
                            @empty
                                <p class="text-sm text-ink-400">Não cabe outro canal aqui ({{ $block['used'] }} de {{ $block['limit'] }}). Para conectar outro, desconecte um em Preferências.</p>
                            @endforelse
                        </div>
                    @endif
                </section>
            @endforeach

            @if ($anyManaged)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="min-w-0 flex-[1_1_300px] text-sm text-ink-400">Vale para o envio na hora, os segurados pelo horário silencioso, o reenvio e o resumo diário.</p>
                    <button class="{{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">Salvar o que cada canal recebe</button>
                </div>
            @endif
        </form>

        @foreach ($blocks->pluck('channels')->flatten()->filter(fn ($channel) => in_array($channel->id, $managed, true)) as $channel)
            <form id="pausar-{{ $channel->id }}" method="POST" action="{{ route('alerts.center.pause', $channel) }}" hidden>
                @csrf
            </form>
        @endforeach
    </div>
@endsection
