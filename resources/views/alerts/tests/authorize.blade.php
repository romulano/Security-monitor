@extends('layouts.app')

@section('title', 'Autorizar um teste · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $last = count($steps);
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $at = fn (int $n) => array_filter(['passo' => $n, 'dominio' => $domain?->host]);
    // Com o rascunho que a regra recusa (passos 5 e 6) ou sem tudo o que o
    // aceite pede (passo 7), não há o que gravar.
    $canContinue = match ($step) {
        1 => true,
        5 => $rule !== null,
        6 => $preview !== null,
        7 => $canAuthorize ?? false,
        default => true,
    };
@endphp

@section('content')
    <div class="space-y-6">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                @include('alerts.partials.crumbs', ['parent' => ['Testes de segurança', route('alerts.tests')], 'here' => 'Autorizar um teste'])
                <h1 class="text-xl font-semibold text-white">
                    Autorizar um teste @if ($domain) · <span class="inline-block max-w-full font-mono text-lg [overflow-wrap:anywhere]">{{ $domain->host }}</span> @endif
                </h1>
                <p class="text-sm text-ink-400">
                    Passo {{ $step }} de {{ $last }} · {{ $steps[$step][0] }}
                    @if ($step === 1 && ! $domain) · uns 5 minutos · separado do Radar e de Notícias @endif
                </p>
            </div>
            @if ($domain?->updated_at)
                @php $saved = $domain->updated_at->copy()->timezone('America/Sao_Paulo'); @endphp
                <span class="text-xs text-ink-400">Rascunho salvo {{ $saved->isSameDay(now()->timezone('America/Sao_Paulo')) ? 'às '.$saved->format('H:i') : 'em '.$saved->format('d/m \à\s H:i') }}</span>
            @endif
        </div>

        <div class="h-1 overflow-hidden rounded bg-ink-850" aria-hidden="true">
            <div class="h-1 bg-accent" style="width: {{ round($step / $last * 100) }}%"></div>
        </div>

        <div class="flex flex-wrap items-start gap-6">
            {{-- No celular, os 7 passos ficam fechados em "Ver os 7 passos" (canvas: Celular-autorizar): o conteúdo do passo
                 vem logo depois do título, e a lista abre sem JS. No computador, a lista fica sempre aberta. --}}
            <details class="group w-full sm:hidden">
                <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg border border-ink-800 px-3 text-sm text-accent">
                    <span class="group-open:hidden">Ver os {{ $last }} passos</span>
                    <span class="hidden group-open:inline">Fechar a lista dos passos</span>
                    <svg aria-hidden="true" class="size-4 shrink-0 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </summary>
                <nav aria-label="Passos da autorização" class="mt-1 flex flex-col gap-0.5">
                    @foreach ($steps as $n => [$title, $sub])
                        @if ($n <= $reached)
                            <a href="{{ route('alerts.tests.authorize', $at($n)) }}" @if ($n === $step) aria-current="step" @endif
                               class="flex min-h-11 items-center gap-3 rounded-lg px-3 {{ $n === $step ? 'bg-ink-850 text-white' : 'text-ink-200 hover:bg-ink-900' }}">{{ $n }}. {{ $title }}</a>
                        @else
                            <span class="flex min-h-11 items-center gap-3 rounded-lg px-3 text-ink-400">{{ $n }}. {{ $title }}</span>
                        @endif
                    @endforeach
                </nav>
            </details>

            <nav aria-label="Passos da autorização" class="hidden flex-[1_1_220px] flex-col gap-0.5 sm:flex">
                @foreach ($steps as $n => [$title, $sub])
                    @php
                        $current = $n === $step;
                        $done = ! $current && $n < $reached;
                        $open = $n <= $reached;
                    @endphp
                    @if ($open)
                        <a href="{{ route('alerts.tests.authorize', $at($n)) }}" @if ($current) aria-current="step" @endif
                           class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 {{ $current ? 'bg-ink-850' : 'hover:bg-ink-900' }}">
                    @else
                        <span class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2">
                    @endif
                            <span class="grid size-6 shrink-0 place-items-center rounded-full border text-xs font-semibold
                                         {{ $current ? 'border-accent bg-accent text-ink-950' : ($done ? 'border-emerald-700 bg-emerald-950 text-emerald-300' : 'border-ink-700 text-ink-400') }}">{{ $n }}</span>
                            <span class="flex flex-col leading-tight">
                                <span class="text-sm font-semibold {{ $current ? 'text-white' : 'text-ink-200' }}">{{ $title }}</span>
                                <span class="text-xs text-ink-400">{{ $current ? 'agora' : ($done ? 'concluído' : $sub) }}</span>
                            </span>
                    @if ($open)
                        </a>
                    @else
                        </span>
                    @endif
                @endforeach
            </nav>

            <div class="min-w-0 flex-[999_1_560px] space-y-4">
                @include('alerts.tests.steps.'.$step)

                <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                    @if ($step > 1)
                        <a href="{{ route('alerts.tests.authorize', $at($step - 1)) }}" class="{{ $button }} border border-ink-800 text-ink-200 hover:border-ink-700">Voltar</a>
                    @else
                        <a href="{{ route('alerts.integrations') }}" class="{{ $button }} border border-ink-800 text-ink-200 hover:border-ink-700">Cancelar</a>
                    @endif

                    @if ($step === 1 && $domain)
                        <a href="{{ route('alerts.tests.authorize', $at(2)) }}" class="{{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">Continuar</a>
                    @elseif ($canContinue)
                        <button form="step-form" class="{{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">{{ $step === $last ? 'Autorizar e agendar' : 'Continuar' }}</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
