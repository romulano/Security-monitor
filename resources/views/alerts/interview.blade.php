@extends('layouts.app')

@section('title', 'Configurar alertas · Security Monitor')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            @include('alerts.partials.crumbs', ['parent' => ['Notícias', route('alerts.news')], 'here' => 'Configurar alertas'])
            <h1 class="text-xl font-semibold text-white">
                Configurar alertas personalizados
                @if ($profile->hasSiblings())
                    <span class="text-ink-400">· {{ $profile->displayName() }}</span>
                @endif
            </h1>
            <p class="text-sm text-ink-400">
                Passo {{ $step }} de {{ count($steps) }}
                @if ($step === 1) · leva uns 4 minutos no total @endif
                @if ($step === 5) · opcional @endif
            </p>
        </div>
        @if ($profile->step_reached > 1 && $profile->updated_at)
            <span class="text-xs text-ink-400">Rascunho salvo às {{ $profile->updated_at->timezone('America/Sao_Paulo')->format('H:i') }}</span>
        @endif
    </div>

    <div class="mt-4 h-1 overflow-hidden rounded bg-ink-850" aria-hidden="true">
        <div class="h-1 bg-accent" style="width: {{ round($step / count($steps) * 100) }}%"></div>
    </div>

    <div class="mt-6 flex flex-wrap items-start gap-6">
        @include('alerts.partials.stepper')

        <div class="min-w-0 flex-[999_1_560px] space-y-4">
            @if ($step === 1)
                <div class="rounded-xl border border-accent/40 bg-accent/5 px-5 py-4">
                    <p class="font-semibold text-white">Vamos montar o seu radar.</p>
                    <p class="mt-1 text-sm text-ink-200">
                        São 7 passos curtos. Com as respostas, o Security Monitor separa as notícias que afetam a sua empresa e avisa
                        no Slack, no Discord, no Telegram ou num webhook. Dá para pular perguntas e editar tudo depois.
                    </p>
                </div>
            @endif

            <div>
                <h2 class="text-lg font-semibold text-white">{{ $text[0] }}</h2>
                <p class="text-sm text-ink-400">{{ $text[1] }}</p>
            </div>

            @if ($step < count($steps))
                <form id="step-form" method="POST" action="{{ route('alerts.interview.update', $profile->urlParams(['passo' => $step])) }}" class="space-y-4" data-interview @isset($newAlert) data-once @endisset>
                    @csrf
                    @method('PUT')

                    @foreach ($questions as $q)
                        @include('alerts.partials.question', ['q' => $q])
                    @endforeach

                    @isset($newAlert)
                        {{-- Alerta novo de empresa: o setor nasce com ele, no fim do passo 1 (como no Radar e no GitHub). --}}
                        @include('alerts.partials.sector-choice', ['what' => 'o alerta e as notícias dele', 'label' => 'Setor da empresa que vê este alerta'] + $newAlert)
                    @endisset

                    @if ($step === 5)
                        <p data-show-if="{{ json_encode(['key' => 'has_domain', 'in' => ['sim']]) }}"
                           @unless (($questions->firstWhere('model.key', 'has_domain')['value'] ?? null) === 'sim') hidden @endunless
                           class="-mt-1 text-xs text-ink-400">
                            Ao salvar, lemos o DNS público do domínio (MX, SPF e outros TXT, NS e o CNAME do www) para sugerir
                            tecnologias. Isso não acessa o site, e nada entra no perfil sem você confirmar.
                        </p>
                        @if ($domain && $dnsSuggestions)
                            {{-- Logo abaixo do domínio; os campos respondem ao formulário próprio (atributo form). --}}
                            @include('alerts.partials.dns-suggestions')
                        @endif
                        @include('alerts.partials.consent')
                    @endif
                </form>

                @if ($step === 5 && $domain && $dnsSuggestions)
                    <form id="dns-sugestoes" method="POST" action="{{ route('alerts.domain.suggestions', $profile->urlParams()) }}">@csrf</form>
                @endif

                @if ($step === 5 && $domain && $domain->consent_at)
                    @include('alerts.partials.domain-status')
                @endif

                <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                    @if ($step > 1)
                        <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => $step - 1])) }}"
                           class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Voltar</a>
                    @else
                        <a href="{{ route('alerts.news') }}"
                           class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Cancelar</a>
                    @endif

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($step > 1)
                            <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => $step + 1])) }}"
                               class="inline-flex min-h-11 items-center rounded-lg px-4 text-sm text-ink-400 hover:text-ink-200">
                                {{ $step === 5 ? 'Pular, faço depois' : 'Pular' }}
                            </a>
                        @endif
                        @if ($step === 5)
                            <button form="step-form" name="acao" value="ficar"
                                    class="inline-flex min-h-11 items-center rounded-lg border border-accent px-4 text-sm font-semibold text-amber-200 hover:bg-accent/10">
                                Salvar
                            </button>
                        @endif
                        <button form="step-form"
                                class="inline-flex min-h-11 items-center rounded-lg bg-accent px-5 text-sm font-semibold text-ink-950 hover:brightness-110">
                            {{ $step === count($steps) - 1 ? 'Revisar' : 'Continuar' }}
                        </button>
                    </div>
                </div>
            @else
                @include('alerts.partials.review')
            @endif
        </div>
    </div>
@endsection
