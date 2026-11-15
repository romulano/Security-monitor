@extends('layouts.app')

@section('title', 'Alertas · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Services\Alerts\AlertInbox;

    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $names = ['news' => 'Notícias', 'radar' => 'Radar de domínios', 'github' => 'GitHub'];
    // Cor de cada fonte: âmbar (notícias, a cor do Security Monitor), verde-água (Radar) e lilás (GitHub).
    $dots = ['news' => 'bg-accent', 'radar' => 'bg-teal-400', 'github' => 'bg-indigo-300'];
    $pills = [
        'on' => 'border-emerald-800 bg-emerald-950/60 text-emerald-300',
        'warn' => 'border-amber-800 bg-amber-950/40 text-amber-200',
        'off' => 'border-ink-700 bg-ink-850 text-ink-400',
    ];
@endphp

@section('content')
    @if (! $integrated)
        {{-- Nada integrado: a página vira o convite para a Integração. --}}
        <div class="mx-auto max-w-2xl space-y-5 py-8">
            <div>
                <h1 class="text-2xl font-semibold text-white">Escolha o que o Security Monitor vai vigiar</h1>
                <p class="mt-2 text-ink-200">
                    Aqui aparece tudo o que o Security Monitor achar para você. Comece integrando uma fonte: cada uma tem a sua entrevista curta,
                    e dá para integrar as outras depois.
                </p>
            </div>
            <ul class="space-y-2">
                @foreach ([
                    ['news', 'Falhas, ataques e incidentes que afetam a sua stack.', '7 passos · uns 4 min'],
                    ['radar', 'Domínios parecidos com o seu, registrados para golpe.', '3 passos · uns 2 min'],
                    ['github', 'Chaves, código privado e a sua marca em código público.', '6 passos · uns 3 min'],
                ] as [$key, $text, $time])
                    <li class="flex flex-wrap items-center gap-3 rounded-xl border border-ink-800 bg-ink-900 px-4 py-3">
                        <span class="size-2.5 shrink-0 rounded-full {{ $dots[$key] }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-[1_1_260px]">
                            <span class="block font-semibold text-white">{{ $names[$key] }}</span>
                            <span class="block text-sm text-ink-400">{{ $text }}</span>
                        </span>
                        <span class="text-xs text-ink-400">{{ $time }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('alerts.integrations') }}" class="{{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">Ir para Integração</a>
                <span class="text-sm text-ink-400">Depois, conecte o Slack, o Discord, o Telegram ou um webhook em Notificadores.</span>
            </div>
        </div>
    @else
        <div class="space-y-5">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="max-w-3xl">
                    <h1 class="text-xl font-semibold text-white">Alertas</h1>
                    <p class="text-sm text-ink-400">Tudo o que o Security Monitor encontrou para você, das três fontes, num lugar só. Filtre por fonte e por situação, veja o motivo, avalie e reenvie daqui.</p>
                </div>
                <a href="{{ route('alerts.integrations') }}" class="{{ $button }} border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white">Abrir Integração</a>
            </div>

            @php
                $broken = $channels->where('status', 'broken');
                $paused = $channels->filter->isPaused();
            @endphp
            @include('alerts.partials.channel-warnings', ['channels' => $channels])

            <section aria-label="Situação das fontes e dos notificadores" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {{-- $key, e não $source: $source é a fonte escolhida no filtro da lista. --}}
                @foreach ($sources as $key => $s)
                    <a href="{{ $s['url'] }}" class="flex flex-col gap-1.5 rounded-xl border border-ink-800 bg-ink-900 px-4 py-3.5 transition hover:border-ink-700 hover:bg-ink-850">
                        <span class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-2 font-semibold text-white"><span class="size-2 rounded-full {{ $dots[$key] }}" aria-hidden="true"></span>{{ $names[$key] }}</span>
                            <span class="rounded-full border px-2 py-px text-xs whitespace-nowrap {{ $pills[$s['tone']] }}">{{ $s['state'] }}</span>
                        </span>
                        <span class="text-sm text-ink-400">{{ $s['line'] }}</span>
                    </a>
                @endforeach
                <a href="{{ route('alerts.notifiers') }}" class="flex flex-col gap-1.5 rounded-xl border border-ink-800 bg-ink-900 px-4 py-3.5 transition hover:border-ink-700 hover:bg-ink-850">
                    <span class="flex items-center justify-between gap-2">
                        <span class="font-semibold text-white">Onde você recebe</span>
                        @if ($channels->isEmpty())
                            <span class="rounded-full border px-2 py-px text-xs {{ $pills['warn'] }}">nenhum app</span>
                        @elseif ($broken->isNotEmpty())
                            <span class="rounded-full border border-red-900 bg-red-950/50 px-2 py-px text-xs text-red-300">reconectar</span>
                        @elseif ($paused->isNotEmpty())
                            <span class="rounded-full border px-2 py-px text-xs {{ $pills['warn'] }}">{{ $paused->count() }} pausado{{ $paused->count() > 1 ? 's' : '' }}</span>
                        @else
                            <span class="rounded-full border px-2 py-px text-xs {{ $pills['on'] }}">ok</span>
                        @endif
                    </span>
                    <span class="text-sm text-ink-400">
                        {{ $channels->isEmpty()
                            ? 'Conecte o Slack, o Discord, o Telegram ou um webhook para receber.'
                            : $channels->map(fn ($c) => $c->appName().' · '.$c->label().($c->isPaused() ? ' (pausado)' : ($c->status !== 'active' ? ' (com problema)' : '')))->join(' · ') }}
                    </span>
                </a>
            </section>

            <section class="overflow-hidden rounded-xl border border-ink-800 bg-ink-900">
                <div class="space-y-3 border-b border-ink-800 px-5 py-3.5">
                    <div>
                        <h2 class="font-semibold text-white">Linha do tempo</h2>
                        <p class="text-sm text-ink-400">
                            Últimos 30 dias: {{ $stats['alerts'] }} {{ $stats['alerts'] === 1 ? 'alerta' : 'alertas' }}
                            · {{ $stats['irrelevant'] }} marcado(s) como não relevante
                            · <span class="text-violet-300">{{ $stats['held'] }} segurado(s) pela IA</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-start gap-2">
                        <nav aria-label="Fonte" class="flex flex-wrap gap-1 rounded-lg border border-ink-800 p-1">
                            @foreach (AlertInbox::SOURCES as $value => $label)
                                @php
                                    $dot = ['noticias' => $dots['news'], 'radar' => $dots['radar'], 'github' => $dots['github']][$value] ?? null;
                                    $current = $source === $value;
                                @endphp
                                <a href="{{ route('alerts.index', array_filter(['fonte' => $value === 'todas' ? null : $value, 'filtro' => $filter === 'todos' ? null : $filter])) }}"
                                   @if ($current) aria-current="page" @endif
                                   class="inline-flex min-h-9 items-center gap-1.5 rounded-md px-3 text-sm {{ $current ? 'bg-ink-800 text-white' : 'text-ink-400 hover:text-ink-200' }}">
                                    @if ($dot)<span class="size-2 rounded-full {{ $dot }}" aria-hidden="true"></span>@endif
                                    {{ $label }}
                                    <span class="font-mono text-[11px] {{ $current ? 'text-ink-200' : 'text-ink-400' }}">{{ $sourceCounts[$value] }}</span>
                                </a>
                            @endforeach
                        </nav>
                        @include('alerts.partials.filters', [
                            'label' => 'Situação',
                            'filters' => AlertInbox::FILTERS,
                            'current' => $filter,
                            'counts' => $counts,
                            'link' => fn ($value) => route('alerts.index', array_filter(['fonte' => $source === 'todas' ? null : $source, 'filtro' => $value === 'todos' ? null : $value])),
                        ])
                    </div>
                </div>

                {{-- Mesma largura de selos nas três fontes: os títulos ficam alinhados. --}}
                @forelse ($items as $item)
                    @if ($item['source'] === 'news')
                        @include('alerts.partials.news-row', ['match' => $item['model'], 'prefix' => 'n', 'hub' => true, 'badges' => 'sm:w-48',
                            'aiCut' => $aiCuts['news'], 'receivers' => $receivers['news']])
                    @elseif ($item['source'] === 'radar')
                        @include('alerts.lookalikes.partials.alert-row', ['alert' => $item['model'], 'watch' => $item['watch'], 'prefix' => 'r', 'badges' => 'sm:w-48',
                            'aiCut' => $aiCuts['radar'], 'many' => $manyRadars])
                    @else
                        @include('alerts.github.partials.finding-row', ['finding' => $item['model'], 'prefix' => 'g', 'hub' => true, 'badges' => 'sm:w-48',
                            'aiCut' => $aiCuts['github'], 'receivers' => $receivers['github']])
                    @endif
                @empty
                    <p class="px-5 py-10 text-center text-sm text-ink-400">
                        @switch($filter)
                            @case('pendentes') Nada pendente: tudo o que a IA deixou passar já foi avaliado. @break
                            @case('relevantes') Nada relevante ainda. Aqui entram os que a IA aprovou e os que você marcar como relevante. @break
                            @case('nao-relevantes') Nada marcado como não relevante. @break
                            @case('segurados-pela-ia') A IA não segurou nada{{ $source === 'todas' ? '' : ' desta fonte' }}. Quando ela julgar que algo não interessa a você, aparece aqui, e você pode marcar como relevante. @break
                            @case('na-hora') Nada saiu na hora{{ $source === 'todas' ? '' : ' desta fonte' }}. @break
                            @case('resumo') Nada ficou só para o resumo diário{{ $source === 'todas' ? '' : ' desta fonte' }}. @break
                            @default
                                {{ $source === 'todas' ? 'Nenhum alerta ainda. Assim que uma das fontes achar algo para você, ele aparece aqui.' : 'Nada desta fonte ainda.' }}
                        @endswitch
                    </p>
                @endforelse

                @if ($items->hasPages())
                    <div class="border-t border-ink-850 px-5 py-3 text-sm">{{ $items->links() }}</div>
                @endif
            </section>
        </div>
    @endif
@endsection
