@extends('layouts.app')

@section('title', 'Monitoramento do GitHub · Security Monitor')

@php
    use App\Models\GithubFinding;
    use App\Models\GithubMonitor;

    $last = count($steps);
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
@endphp

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            @include('alerts.partials.crumbs', ['parent' => ['GitHub', route('alerts.github')], 'here' => 'Configurar o monitoramento'])
            <h1 class="text-xl font-semibold text-white">Monitoramento do GitHub</h1>
            <p class="text-sm text-ink-400">
                Passo {{ $step }} de {{ $last }}
                @if ($step === 1) · leva uns 3 minutos · separado da entrevista de alertas @endif
                @if ($step === 4) · opcional @endif
            </p>
        </div>
        @if ($monitor->step_reached > 1 && $monitor->updated_at)
            <span class="text-xs text-ink-400">Rascunho salvo às {{ $monitor->updated_at->timezone('America/Sao_Paulo')->format('H:i') }}</span>
        @endif
    </div>

    <div class="mt-4 h-1 overflow-hidden rounded bg-ink-850" aria-hidden="true">
        <div class="h-1 bg-accent" style="width: {{ round($step / $last * 100) }}%"></div>
    </div>

    <div class="mt-6 flex flex-wrap items-start gap-6">
        <nav aria-label="Passos da entrevista" class="flex flex-[1_1_220px] flex-col gap-0.5">
            @foreach ($steps as $n => [$title, , $sub])
                @php
                    $current = $n === $step;
                    $done = ! $current && $n < $monitor->step_reached;
                @endphp
                <a href="{{ route('alerts.github.interview', $monitor->urlParams(['passo' => $n])) }}" @if ($current) aria-current="step" @endif
                   class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 {{ $current ? 'bg-ink-850' : 'hover:bg-ink-900' }}">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full border text-xs font-semibold
                                 {{ $current ? 'border-accent bg-accent text-ink-950' : ($done ? 'border-emerald-700 bg-emerald-950 text-emerald-300' : 'border-ink-700 text-ink-400') }}">{{ $n }}</span>
                    <span class="flex flex-col leading-tight">
                        <span class="text-sm font-semibold {{ $current ? 'text-white' : 'text-ink-200' }}">{{ $title }}</span>
                        <span class="text-xs text-ink-400">{{ $current ? 'agora' : ($done ? 'concluído' : $sub) }}</span>
                    </span>
                </a>
            @endforeach
        </nav>

        <div class="min-w-0 flex-[999_1_560px] space-y-4">
            @if ($step === 1)
                <div class="rounded-xl border border-accent/40 bg-accent/5 px-5 py-4">
                    <p class="font-semibold text-white">O que só deveria existir dentro da empresa, procurado no GitHub público.</p>
                    <p class="mt-1 text-sm text-ink-200">
                        Uma vez por dia o Security Monitor procura em código e repositórios públicos os nomes que você informar aqui. Cada achado passa por
                        duas análises da IA: a primeira classifica (Critical, High, Medium ou Low) e a segunda decide se vale o aviso, aprendendo
                        com as suas avaliações. Nunca pedimos nem guardamos o valor de uma chave.
                    </p>
                </div>
            @endif

            <div>
                <h2 class="text-lg font-semibold text-white">{{ $text[0] }}</h2>
                <p class="text-sm text-ink-400">{{ $text[1] }}</p>
            </div>

            @if ($step < $last)
                <form id="step-form" method="POST" action="{{ route('alerts.github.interview.update', $monitor->urlParams(['passo' => $step])) }}" class="space-y-4" data-interview @unless ($monitor->exists) data-once @endunless>
                    @csrf
                    @method('PUT')

                    @foreach ($fields as $f)
                        @include('alerts.github.partials.field', ['f' => $f])
                    @endforeach

                    @if ($step === 1)
                    @endif

                    @if ($step === 4 && $monitor->extra_text)
                        @include('alerts.github.partials.extra-status')
                    @endif

                    @if ($step === 5)
                        <details class="rounded-xl border border-ink-800 bg-ink-900 p-5" open>
                            <summary class="cursor-pointer text-sm font-semibold text-white">O que cada nível e cada porcentagem significam</summary>
                            <div class="mt-4">@include('alerts.github.partials.levels')</div>
                        </details>
                    @endif
                </form>

                <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                    @if ($step > 1)
                        <a href="{{ route('alerts.github.interview', $monitor->urlParams(['passo' => $step - 1])) }}" class="{{ $button }} border border-ink-800 text-ink-200 hover:border-ink-700">Voltar</a>
                    @else
                        <a href="{{ route('alerts.github', $monitor->urlParams()) }}" class="{{ $button }} border border-ink-800 text-ink-200 hover:border-ink-700">Cancelar</a>
                    @endif

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($step > 1)
                            <a href="{{ route('alerts.github.interview', $monitor->urlParams(['passo' => $step + 1])) }}" class="{{ $button }} text-ink-400 hover:text-ink-200">Pular</a>
                        @endif
                        <button form="step-form" class="{{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">
                            {{ $step === $last - 1 ? 'Revisar' : 'Continuar' }}
                        </button>
                    </div>
                </div>
            @else
                @include('alerts.github.partials.review')
            @endif
        </div>
    </div>
@endsection
