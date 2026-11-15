@extends('layouts.app')

@section('title', 'Integração · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $primary = $button.' bg-accent font-semibold text-ink-950 hover:brightness-110';
    $secondary = $button.' border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white';
    $pills = [
        'on' => ['border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
        'warn' => ['border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
        'off' => ['border-ink-700 bg-ink-850 text-ink-400', 'bg-ink-400'],
    ];
    $when = fn ($date) => $date ? $date->timezone('America/Sao_Paulo')->format('d/m H:i') : 'ainda não';
    // "Pendentes" e "segurados pela IA" levam para Alertas, já filtrado na fonte.
    $pending = fn (string $source) => route('alerts.index', ['fonte' => $source, 'filtro' => 'pendentes']);
    $held = fn (string $source) => route('alerts.index', ['fonte' => $source, 'filtro' => 'segurados-pela-ia']);

    // Notícias: a entrevista de sempre (7 passos), um alerta por produto ou site.
    $draft = $profiles->whereNull('completed_at')->first();
    $newsOn = $completed->isNotEmpty();
    $news = match (true) {
        $newsOn && $completed->every->isPaused() => ['pausado', 'off'],
        $newsOn => ['ativo', 'on'],
        (bool) $draft => ['rascunho · passo '.$draft->step_reached, 'warn'],
        default => ['não integrado', 'off'],
    };

    // Radar: a entrevista dele (3 passos), um Radar por domínio.
    $ready = $watches->filter->isCompleted();
    $radar = match (true) {
        $ready->contains->isActive() => ['ativo', 'on'],
        $ready->contains->isWaiting() => ['esperando o TXT', 'warn'],
        $ready->isNotEmpty() => ['pausado', 'off'],
        (bool) $radarDraft => ['rascunho · passo '.$radarDraft->step_reached, 'warn'],
        default => ['não integrado', 'off'],
    };

    // GitHub: a entrevista dele (6 passos); um monitoramento na conta pessoal, vários na empresa (P6).
    $githubReady = $monitors->filter->isCompleted();
    $monitor = $githubReady->first(fn ($m) => ! $m->isPaused()) ?? $githubReady->first();
    $githubOn = $githubReady->isNotEmpty();
    $githubActive = $githubReady->reject->isPaused()->count();
    $github = match (true) {
        $githubOn && $githubActive === 0 => ['pausado', 'off'],
        $githubActive > 1 => [$githubActive.' ativos', 'on'],
        $githubOn => ['ativo', 'on'],
        (bool) ($githubDraft ?? $monitors->first()) => ['rascunho · passo '.($githubDraft ?? $monitors->first())->step_reached, 'warn'],
        default => ['não integrado', 'off'],
    };

    $cards = [
        [
            'key' => 'news',
            'name' => 'Notícias',
            'icon' => ['bg-accent/15 text-accent', '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/>'],
            'pill' => $news,
            'on' => $newsOn,
            'url' => route('alerts.news'),
            'text' => 'Falhas, ataques e incidentes que afetam o que vocês usam. Um alerta por produto ou site.',
            'bullets' => ['Entrevista de 7 passos, uns 4 minutos', 'Stack, setor, marcas e o domínio (opcional)', 'A IA dá o parecer antes de cada aviso'],
            'numbers' => [
                [$completed->count(), $completed->count() === 1 ? 'alerta' : 'alertas', null, null],
                [$summary['news']['recent'] ?? 0, 'nos últimos 30 dias', null, null],
                [$summary['news']['pending'] ?? 0, 'pendentes', $pending('noticias'), 'text-accent'],
            ],
            'foot' => 'Entrevista de cada alerta, domínio e pausa ficam na página.',
        ],
        [
            'key' => 'radar',
            'name' => 'Radar de domínios',
            'icon' => ['bg-teal-400/10 text-teal-300', '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'],
            'pill' => $radar,
            'on' => $ready->isNotEmpty(),
            'url' => route('alerts.lookalikes'),
            'text' => 'Domínios parecidos com o seu, registrados para golpe. Abrimos cada um e montamos o kit de denúncia.',
            'bullets' => [
                'Entrevista de 3 passos: domínio, marca e posse',
                $proven->isNotEmpty() ? 'Reaproveita '.$proven->first()->domain.', já comprovado em Notícias' : 'Reaproveita o domínio já comprovado em Notícias',
                'Um Radar por domínio, até '.\App\Models\LookalikeWatch::MAX_PER_USER,
            ],
            'numbers' => [
                [$ready->count(), $ready->count() === 1 ? 'domínio vigiado' : 'domínios vigiados', null, null],
                [$threats, $threats === 1 ? 'ameaça aberta' : 'ameaças abertas', route('alerts.lookalikes'), 'text-red-300'],
                [$summary['radar']['pending'] ?? 0, 'pendentes', $pending('radar'), 'text-accent'],
            ],
            'foot' => 'Última varredura: '.$when($lastRadarScan).'.',
        ],
        [
            'key' => 'github',
            'name' => 'GitHub',
            'icon' => ['bg-indigo-300/10 text-indigo-300', '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>'],
            'pill' => $github,
            'on' => $githubOn,
            'url' => route('alerts.github'),
            'text' => 'Chaves que alguém subiu por engano, código privado publicado e repositórios que imitam a sua marca.',
            'bullets' => ['Entrevista de 6 passos, uns 3 minutos', 'Só o nome das chaves, nunca o valor', 'Classificação e parecer da IA em cada achado'],
            'numbers' => [
                [$summary['github']['recent'] ?? 0, 'nos últimos 30 dias', null, null],
                [$summary['github']['pending'] ?? 0, 'pendentes', $pending('github'), 'text-accent'],
                [$summary['github']['held'] ?? 0, 'segurados pela IA', $held('github'), 'text-violet-300'],
            ],
            'foot' => ($githubOn && $githubReady->count() === 1 ? 'Aviso a partir de '.$monitor->levelLabel().' · ' : '').'Última varredura: '.$when($githubReady->max('last_scan_at')).'.',
        ],
    ];
@endphp

@section('content')
    <div class="mx-auto max-w-6xl space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-3xl">
                <h1 class="text-xl font-semibold text-white">Integração</h1>
                <p class="text-sm text-ink-400">
                    @if ($tests)
                        O que o Security Monitor vigia e testa para você. Cada frente tem a sua entrevista e a sua página: abra uma para ver tudo o que ela achou,
                        refazer a entrevista ou pausar. O que chega das fontes fica junto em <a href="{{ route('alerts.index') }}" class="text-accent hover:underline">Alertas</a>.
                    @else
                        O que o Security Monitor vigia para você. Cada fonte tem a sua entrevista e a sua página: abra uma para ver tudo o que ela achou,
                        refazer a entrevista ou pausar. O que chega das três fica junto em <a href="{{ route('alerts.index') }}" class="text-accent hover:underline">Alertas</a>.
                    @endif
                </p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            @foreach ($cards as $card)
                <section class="flex flex-col gap-4 rounded-xl border border-ink-800 bg-ink-900 p-5" data-source="{{ $card['key'] }}">
                    <div class="flex items-center gap-3">
                        <span class="grid size-12 shrink-0 place-items-center rounded-xl {{ $card['icon'][0] }}" aria-hidden="true">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $card['icon'][1] !!}</svg>
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-white">
                                @if ($card['on'])
                                    <a href="{{ $card['url'] }}" class="hover:text-accent">{{ $card['name'] }}</a>
                                @else
                                    {{ $card['name'] }}
                                @endif
                            </h2>
                            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $pills[$card['pill'][1]][0] }}"><span class="size-1.5 rounded-full {{ $pills[$card['pill'][1]][1] }}"></span>{{ $card['pill'][0] }}</span>
                        </div>
                    </div>
                    <p class="text-sm text-ink-200">{{ $card['text'] }}</p>

                    @if ($card['on'])
                        <dl class="grid grid-cols-3 divide-x divide-ink-800 rounded-lg border border-ink-800 bg-ink-950/40">
                            @foreach ($card['numbers'] as [$value, $label, $link, $tone])
                                <div class="flex min-w-0 flex-col-reverse px-3 py-2.5">
                                    <dt class="text-xs leading-4 text-ink-400">{{ $label }}</dt>
                                    <dd class="truncate font-mono text-lg font-semibold {{ $tone && $value ? $tone : 'text-white' }}">
                                        @if ($link && $value)
                                            <a href="{{ $link }}" class="hover:underline">{{ $value }}</a>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        <p class="text-xs text-ink-400">{{ $card['foot'] }}</p>
                    @else
                        <ul class="list-disc space-y-0.5 pl-5 text-sm text-ink-400">
                            @foreach ($card['bullets'] as $bullet)
                                <li>{{ $bullet }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-auto flex flex-wrap items-center gap-2">
                        @switch($card['key'])
                            @case('news')
                                @if ($newsOn)
                                    <a href="{{ route('alerts.news') }}" class="{{ $primary }}">Abrir Notícias</a>
                                    @if ($canAddProfile)
                                        <form method="POST" action="{{ route('alerts.store') }}">
                                            @csrf
                                            <button class="{{ $button }} text-accent hover:underline">Novo alerta</button>
                                        </form>
                                    @endif
                                @elseif ($draft && $draftManaged)
                                    <a href="{{ route('alerts.interview', $draft->urlParams(['passo' => $draft->step_reached])) }}" class="{{ $primary }}">Continuar a entrevista (passo {{ $draft->step_reached }})</a>
                                @elseif ($canAddProfile)
                                    <a href="{{ route('alerts.interview', ['passo' => 1]) }}" class="{{ $primary }}">Integrar Notícias</a>
                                @endif
                                @break
                            @case('radar')
                                @if ($ready->isNotEmpty())
                                    <a href="{{ route('alerts.lookalikes') }}" class="{{ $primary }}">Abrir o Radar</a>
                                    @if ($radarDraft)
                                        <a href="{{ route('alerts.lookalikes.interview', ['passo' => $radarDraft->step_reached, 'radar' => $radarDraft->id]) }}" class="{{ $button }} text-accent hover:underline">Continuar {{ $radarDraft->domain?->domain ?? 'a entrevista' }} (passo {{ $radarDraft->step_reached }})</a>
                                    @elseif ($canAddWatch)
                                        <a href="{{ route('alerts.lookalikes.interview', ['passo' => 1]) }}" class="{{ $button }} text-accent hover:underline">Adicionar domínio</a>
                                    @endif
                                @elseif ($radarDraft)
                                    <a href="{{ route('alerts.lookalikes.interview', ['passo' => $radarDraft->step_reached, 'radar' => $radarDraft->id]) }}" class="{{ $primary }}">Continuar a entrevista (passo {{ $radarDraft->step_reached }})</a>
                                @elseif ($canAddWatch)
                                    <a href="{{ route('alerts.lookalikes.interview', ['passo' => 1]) }}" class="{{ $primary }}">Integrar o Radar</a>
                                @endif
                                @break
                            @default
                                @if ($githubOn)
                                    <a href="{{ route('alerts.github', $monitor->urlParams()) }}" class="{{ $primary }}">Abrir o GitHub</a>
                                    @if ($githubDraft)
                                        <a href="{{ route('alerts.github.interview', $githubDraft->urlParams(['passo' => $githubDraft->step_reached])) }}" class="{{ $button }} text-accent hover:underline">Continuar a entrevista (passo {{ $githubDraft->step_reached }})</a>
                                    @elseif ($canAddMonitor)
                                        <a href="{{ route('alerts.github.interview', ['passo' => 1]) }}" class="{{ $button }} text-accent hover:underline">Adicionar monitoramento</a>
                                    @elseif ($githubEditable)
                                        <a href="{{ route('alerts.github.interview', $githubEditable->urlParams(['passo' => 1])) }}" class="{{ $button }} text-accent hover:underline">Editar entrevista</a>
                                    @endif
                                @elseif ($githubDraft)
                                    <a href="{{ route('alerts.github.interview', $githubDraft->urlParams(['passo' => $githubDraft->step_reached])) }}" class="{{ $primary }}">Continuar a entrevista (passo {{ $githubDraft->step_reached }})</a>
                                @elseif ($canAddMonitor)
                                    <a href="{{ route('alerts.github.interview', ['passo' => 1]) }}" class="{{ $primary }}">Integrar o GitHub</a>
                                @endif
                        @endswitch
                    </div>
                </section>
            @endforeach
        </div>

        @include('alerts.keys.partials.card', ['card' => $keys, 'pills' => $pills, 'primary' => $primary])

        @if ($tests)
            @include('alerts.tests.partials.card', ['card' => $tests, 'pills' => $pills, 'primary' => $primary, 'button' => $button])
        @endif

        <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink-800 px-5 py-4">
            <p class="min-w-0 flex-[1_1_420px] text-sm text-ink-200">
                <b class="text-white">Onde avisar:</b>
                @if ($channels->isEmpty())
                    nenhum app conectado. Os alertas ficam guardados em Alertas até você conectar o Slack, o Discord, o Telegram ou um webhook.
                @else
                    {{ $channels->map(fn ($c) => $c->appName().' ('.$c->label().($c->isPaused() ? ', pausado' : ($c->status !== 'active' ? ', com problema' : '')).')')->join(' · ') }}.
                    O que cada app recebe fica em Notificadores.
                @endif
            </p>
            <a href="{{ route('alerts.notifiers') }}" class="{{ $secondary }} whitespace-nowrap">{{ $channels->isEmpty() ? 'Escolher onde receber' : 'Abrir Notificadores' }}</a>
        </section>
    </div>
@endsection
