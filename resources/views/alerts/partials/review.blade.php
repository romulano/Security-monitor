@php
    $preview = $review['preview'];
    $hasChannel = auth()->user()->notificationChannels()->exists();
@endphp

@if ($review['missing'])
    <div class="rounded-xl border border-red-900 bg-red-950/40 px-5 py-4 text-sm text-red-200">
        Falta responder “{{ $review['missing']->prompt }}”.
        <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => $review['missing']->step])) }}" class="font-semibold text-red-100 underline">Ir para o passo {{ $review['missing']->step }}</a>
    </div>
@endif

<section class="flex flex-wrap items-center gap-x-8 gap-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
    @if ($preview['error'])
        <p class="text-sm text-ink-400">{{ $preview['error'] }} O perfil pode ser salvo assim mesmo.</p>
    @elseif (! $profile->match_query)
        <p class="text-sm text-ink-400">
            Ainda não há tecnologias, termos ou marcas no perfil: sem isso não temos o que procurar.
            <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => 2])) }}">Marcar a stack</a>.
        </p>
    @else
        <div class="min-w-0 flex-[1_1_260px]">
            <p class="text-sm text-ink-400">Com este perfil, nos últimos 30 dias você teria recebido</p>
            <p class="mt-1 text-4xl font-bold text-white">{{ $preview['total'] }} {{ $preview['total'] === 1 ? 'alerta' : 'alertas' }}</p>
            <p class="mt-2 flex flex-wrap items-center gap-1.5">
                @foreach ($preview['by_level'] as $level => $count)
                    <span class="text-xs text-ink-400">{{ $count }}</span><x-criticity :level="$level" size="xs" />
                @endforeach
                <span class="text-sm text-ink-400">· cerca de {{ number_format($preview['per_week'], 1, ',', '') }} por semana</span>
            </p>
        </div>
        <p class="min-w-0 flex-[1_1_260px] text-sm text-ink-400">
            Achou muito? Suba para “Somente Critical” ou desligue as atualizações de casos. Achou pouco? Ligue “notícias do meu setor”
            ou marque mais tecnologias.
        </p>
    @endif
</section>

@if (! empty($preview['examples']))
    <section class="overflow-hidden rounded-xl border border-ink-800 bg-ink-900">
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-ink-800 px-5 py-3">
            <h3 class="text-[15px] font-semibold text-white">Alguns dos casos que teriam chegado</h3>
            @if ($profile->compiled_query)
                <a href="{{ route('feed.index', ['q' => $profile->compiled_query]) }}" class="text-sm text-accent hover:underline">Ver no feed</a>
            @endif
        </div>
        @foreach ($preview['examples'] as $example)
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-ink-850 px-5 py-3 first:border-t-0">
                <x-criticity :level="$example['criticity']" size="xs" />
                <a href="{{ $example['uuid'] ? route('feed.show', $example['uuid']) : '#' }}"
                   class="min-w-0 flex-[1_1_320px] font-semibold text-white hover:text-accent">{{ $example['title'] }}</a>
                @if ($example['latest_at'])
                    <span class="text-xs text-ink-400">{{ \Illuminate\Support\Carbon::parse($example['latest_at'])->timezone('America/Sao_Paulo')->format('d/m') }}</span>
                @endif
                <span class="basis-full text-sm text-ink-400">Por quê: <span class="text-amber-200">{{ $example['why'] }}</span>{{ $example['brand'] ? ' · cita a sua empresa' : '' }}</span>
            </div>
        @endforeach
    </section>
@endif

<section class="overflow-hidden rounded-xl border border-ink-800 bg-ink-900">
    <div class="border-b border-ink-800 px-5 py-3">
        <h3 class="text-[15px] font-semibold text-white">Suas respostas</h3>
    </div>
    <dl>
        @foreach ($steps as $n => [$title])
            @continue($n === count($steps))
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t border-ink-850 px-5 py-3 first:border-t-0">
                <dt class="w-44 shrink-0 text-sm text-ink-400">{{ $n }} · {{ $title }}</dt>
                <dd class="min-w-0 flex-[1_1_300px] text-sm text-ink-200">
                    @php
                        $answered = collect($review['steps'][$n] ?? [])->reject(fn ($a) => $a['answer'] === '—');
                    @endphp
                    @if ($n === 5 && $domain)
                        <span class="font-mono">{{ $domain->domain }}</span>
                        · {{ $domain->consent_at ? ($domain->verified_at ? 'verificado, descoberta autorizada' : 'descoberta autorizada, aguardando o TXT') : 'sem descoberta automática' }}
                    @elseif ($answered->isEmpty())
                        <span class="text-ink-400">sem respostas</span>
                    @else
                        @foreach ($answered as $a)
                            <span class="text-ink-400">{{ rtrim($a['prompt'], '?') }}:</span> {{ $a['answer'] }}@if (! $loop->last)<span class="text-ink-700"> · </span>@endif
                        @endforeach
                    @endif
                </dd>
                <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => $n])) }}" class="shrink-0 text-sm text-accent hover:underline">Editar</a>
            </div>
        @endforeach
    </dl>
</section>

@if ($profile->compiled_query)
    <details class="rounded-xl border border-ink-800 bg-ink-900 px-5 py-4">
        <summary class="cursor-pointer text-sm font-semibold text-ink-200">Ver a query gerada (para quem gosta de detalhe)</summary>
        <pre class="mt-3 overflow-x-auto rounded-lg border border-ink-800 bg-ink-950 p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-ink-200">{{ $profile->compiled_query }}</pre>
    </details>
@endif

<form method="POST" action="{{ route('alerts.interview.finish', $profile->urlParams()) }}" class="flex flex-wrap items-center justify-between gap-3 pt-2">
    @csrf
    <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => count($steps) - 1])) }}"
       class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Voltar</a>
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm text-ink-400">
            {{ $hasChannel ? 'As mudanças valem a partir do próximo caso.' : 'Os alertas começam assim que houver um canal conectado.' }}
        </span>
        <button class="inline-flex min-h-11 items-center rounded-lg bg-accent px-5 text-sm font-semibold text-ink-950 hover:brightness-110">
            {{ $hasChannel ? 'Salvar perfil' : 'Salvar e escolher onde receber' }}
        </button>
    </div>
</form>
