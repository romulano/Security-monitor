@extends('layouts.app')

@section('title', 'Notícias · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Models\AlertProfile;
    use App\Services\Alerts\AlertPanel;

    $draft = $profiles->first();
    $many = $profiles->count() > 1;
    $active = $channels->filter->isActive();
    // Quem recebe as notícias agora (conectado, sem pausa e com as notícias ligadas em Notificadores).
    $receivers = $channels->filter->receives('news');
    $apps = $channels->filter->wants('news')->map->appName()->join(', ', ' e ') ?: ($channels->map->appName()->join(', ', ' e ') ?: 'Slack');
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
@endphp

@section('content')
    @if ($completed->isEmpty() && ! $many)
        {{-- Ainda sem alerta: a página vira o convite para a entrevista. --}}
        <div class="mx-auto max-w-2xl py-8">
            @include('alerts.partials.crumbs', ['here' => 'Notícias'])
            <h1 class="text-2xl font-semibold text-white">Receba só as notícias que afetam a sua empresa</h1>
            <p class="mt-2 text-ink-200">
                Responda uma entrevista curta sobre a sua stack. Quando sair uma notícia sobre o que vocês usam, o Security Monitor avisa no
                Slack, no Discord, no Telegram ou num webhook na hora, e todo dia de manhã manda um resumo do que entrou.
            </p>
            <ul class="mt-5 space-y-2 text-sm text-ink-200">
                <li class="flex gap-2"><span class="text-accent">1.</span> 7 passos, uns 4 minutos. Dá para pular e voltar depois.</li>
                <li class="flex gap-2"><span class="text-accent">2.</span> No fim, uma prévia: quantos alertas o seu perfil teria gerado no último mês.</li>
                <li class="flex gap-2"><span class="text-accent">3.</span> Conecte o Slack, o Discord, o Telegram ou um webhook e escolha: na hora, resumo diário ou os dois.</li>
                <li class="flex gap-2"><span class="text-accent">4.</span> Tem mais de um produto ou site? Depois você cria um alerta para cada um.</li>
            </ul>
            <a href="{{ route('alerts.interview', $draft ? $draft->urlParams(['passo' => $draft->step_reached]) : ['passo' => 1]) }}"
               class="mt-6 {{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">
                {{ $draft && $draft->step_reached > 1 ? 'Continuar a entrevista (passo '.$draft->step_reached.')' : 'Começar a entrevista' }}
            </a>
        </div>
    @else
        @php
            $brokenChannels = $channels->where('status', 'broken');
            $broken = $active->isEmpty() && $brokenChannels->isNotEmpty();
        @endphp

        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    @include('alerts.partials.crumbs', ['here' => 'Notícias'])
                    <h1 class="text-xl font-semibold text-white">Notícias</h1>
                    <p class="text-sm text-ink-400">Um alerta por produto ou site, cada um com a sua entrevista e o seu domínio. Todos chegam nos apps que recebem notícias em <a href="{{ route('alerts.notifiers') }}" class="text-accent hover:underline">Notificadores</a>.</p>
                </div>
                @if ($canCreate && $companyAccount)
                    {{-- Alerta de empresa: nasce no passo 1 com o setor (startWith, como o GitHub); copiar escolhe o setor aqui. --}}
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="flex flex-col gap-1">
                            <a href="{{ route('alerts.interview', ['passo' => 1]) }}" class="{{ $button }} inline-flex items-center bg-accent font-semibold text-ink-950 hover:brightness-110">Novo alerta</a>
                            @if ($alertsUsage)
                                @php [$alertsUsed, $alertsMax] = $alertsUsage; @endphp
                                <p @class(['text-xs', 'text-amber-300' => $alertsMax > 0 && $alertsUsed / $alertsMax >= 0.8, 'text-ink-400' => ! ($alertsMax > 0 && $alertsUsed / $alertsMax >= 0.8)]) data-alerts-usage>
                                    {{ $alertsUsed }} de {{ $alertsMax }} alertas da empresa
                                </p>
                            @endif
                        </div>
                        @php $copiable = $completed->filter(fn ($p) => in_array($p->id, $managed, true)); @endphp
                        @if ($copiable->isNotEmpty())
                            <details class="w-full sm:w-auto" @if ($errors->has('setor')) open @endif>
                                <summary class="{{ $button }} inline-flex cursor-pointer items-center text-accent hover:underline">Copiar um alerta</summary>
                                <form method="POST" action="{{ route('alerts.store') }}" class="mt-3 space-y-3 sm:w-96">
                                    @csrf
                                    <label for="copiar" class="sr-only">Começar com as respostas de</label>
                                    <select id="copiar" name="copiar" required
                                            class="min-h-11 w-full rounded-lg border border-ink-800 bg-ink-900 px-3 text-sm text-ink-200 [color-scheme:dark]">
                                        @foreach ($copiable as $p)
                                            <option value="{{ $p->id }}">Copiar respostas de “{{ $p->displayName() }}”</option>
                                        @endforeach
                                    </select>
                                    @include('alerts.partials.sector-choice', ['what' => 'o alerta e as notícias dele', 'label' => 'Setor da empresa que vê este alerta'])
                                    <button class="{{ $button }} bg-accent font-semibold text-ink-950 hover:brightness-110">Criar a cópia</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @elseif ($canCreate)
                    <form method="POST" action="{{ route('alerts.store') }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        @if ($completed->isNotEmpty())
                            <label for="copiar" class="sr-only">Começar com as respostas de</label>
                            <select id="copiar" name="copiar"
                                    class="min-h-11 rounded-lg border border-ink-800 bg-ink-900 px-3 text-sm text-ink-200 [color-scheme:dark]">
                                <option value="">Começar do zero</option>
                                @foreach ($completed as $p)
                                    <option value="{{ $p->id }}">Copiar respostas de “{{ $p->displayName() }}”</option>
                                @endforeach
                            </select>
                        @endif
                        <button class="{{ $button }} bg-accent font-semibold text-ink-950 hover:brightness-110">Novo alerta</button>
                    </form>
                @elseif ($alertsFull)
                    <p class="max-w-sm text-sm text-amber-300" data-alerts-full>{{ $alertsFull }}</p>
                @endif
            </div>

            @include('alerts.partials.channel-warnings', ['channels' => $channels, 'receivers' => $receivers])

            @php $pausedChannels = $channels->filter->isPaused(); @endphp
            @if ($pausedChannels->isNotEmpty())
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-800 bg-amber-950/30 px-4 py-3">
                    <p class="min-w-0 flex-[1_1_400px] text-sm text-amber-100">
                        <b class="text-white">Envio pausado no {{ $pausedChannels->map->appName()->join(', no ', ' e no ') }}.</b>
                        {{ $receivers->isEmpty() ? 'Os alertas continuam guardados aqui até você retomar.' : 'Os outros apps continuam recebendo.' }}
                    </p>
                    <a href="{{ route('alerts.notifiers') }}" class="{{ $button }} bg-amber-300 font-semibold text-amber-950">Abrir Notificadores</a>
                </div>
            @elseif ($active->isNotEmpty() && $receivers->isEmpty())
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-800 bg-amber-950/30 px-4 py-3">
                    <p class="min-w-0 flex-[1_1_400px] text-sm text-amber-100">
                        <b class="text-white">Nenhum app recebe notícias.</b> Os alertas continuam guardados aqui. Ligue as notícias em um app em Notificadores para recebê-los.
                    </p>
                    <a href="{{ route('alerts.notifiers') }}" class="{{ $button }} bg-amber-300 font-semibold text-amber-950">Abrir Notificadores</a>
                </div>
            @endif

            @if ($channels->isEmpty())
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-accent/40 bg-accent/5 px-4 py-3">
                    <p class="min-w-0 flex-[1_1_400px] text-sm text-ink-200">
                        <b class="text-white">Falta escolher onde receber.</b> Os alertas já estão sendo guardados aqui; conecte o Slack, o Discord, o Telegram ou um webhook para recebê-los.
                    </p>
                    <a href="{{ route('alerts.notifiers') }}" class="{{ $button }} bg-accent font-semibold text-ink-950">Escolher onde receber</a>
                </div>
            @endif

            @foreach ($profiles as $profile)
                @php $domain = $profile->domains->first(); @endphp
                @if ($walls = $domain?->walls())
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-800 bg-amber-950/30 px-4 py-3">
                        <div class="min-w-0 flex-[1_1_400px] space-y-1 text-sm text-amber-100">
                            <p><b class="text-white">Não conseguimos ler tudo em {{ $domain->domain }}{{ $many ? ' (alerta “'.$profile->displayName().'”)' : '' }}.</b>
                                A descoberta não viu o que está atrás {{ count($walls) === 1 ? 'desta página' : 'destas páginas' }}, então o perfil pode estar incompleto:</p>
                            <ul class="space-y-0.5">
                                @foreach ($walls as $wall)
                                    <li>
                                        @isset($wall['url'])
                                            <span class="font-mono break-all">{{ $wall['url'] }}</span> ·
                                            {{ ($wall['por'] ?? null) === 'cloudflare' ? 'desafio anti-robô da Cloudflare' : 'proteção anti-robô do site' }}
                                        @else
                                            {{ ucfirst($wall['texto']) }}
                                        @endisset
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => 5])) }}#{{ $domain->usesCloudflare() ? 'liberar-cloudflare' : 'descoberta' }}"
                           class="{{ $button }} bg-amber-300 font-semibold text-amber-950 hover:brightness-110">Ver como liberar</a>
                    </div>
                @endif
            @endforeach

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($profiles as $profile)
                    @php
                        $domain = $profile->domains->first();
                        $discovered = $domain ? $domain->findings->where('status', 'accepted')->count() : 0;
                        $pill = match (true) {
                            ! $profile->completed_at => ['rascunho · passo '.$profile->step_reached, 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
                            $profile->isPaused() => ['pausado', 'border-ink-700 bg-ink-850 text-ink-400', 'bg-ink-400'],
                            $broken => [$brokenChannels->count() === 1 ? $brokenChannels->first()->appName().' com problema' : 'canais com problema', 'border-red-900 bg-red-950/50 text-red-300', 'bg-red-400'],
                            default => ['ativo', 'border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
                        };
                    @endphp
                    <section class="flex flex-col gap-3 rounded-xl border border-ink-800 bg-ink-900 p-5" data-alert="{{ $profile->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[11px] tracking-widest text-ink-400 uppercase">Alerta</p>
                                <h2 class="truncate font-semibold text-white">{{ $profile->displayName() }}</h2>
                            </div>
                            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $pill[1] }}">
                                <span class="size-1.5 rounded-full {{ $pill[2] }}"></span>{{ $pill[0] }}
                            </span>
                        </div>

                        @if ($profile->completed_at)
                            <p class="text-sm text-ink-200">
                                {{ $profile->levelLabel() }} · {{ $profile->techs_count }} {{ $profile->techs_count === 1 ? 'tecnologia' : 'tecnologias' }}
                                · {{ $profile->terms_count }} termos · {{ count($profile->brands ?? []) }} marcas
                            </p>
                        @else
                            <p class="text-sm text-ink-400">Entrevista não concluída: este alerta ainda não recebe nada.</p>
                        @endif

                        <p class="text-sm text-ink-400">
                            @if ($domain)
                                <span class="font-mono text-ink-200">{{ $domain->domain }}</span> ·
                                @if (! $domain->consent_at)
                                    sem descoberta automática
                                @elseif (! $domain->verified_at)
                                    aguardando o registro TXT
                                @elseif (! $domain->last_run_at)
                                    verificado · descoberta na fila
                                @else
                                    verificado · {{ $discovered }} {{ $discovered === 1 ? 'tecnologia identificada, no perfil' : 'tecnologias identificadas, todas no perfil' }}
                                @endif
                            @else
                                Sem domínio
                            @endif
                        </p>

                        @if ($profile->company_id)
                            @php $manages = in_array($profile->id, $managed, true); @endphp
                            @include('alerts.partials.sector-change', [
                                'item' => $profile, 'action' => route('alerts.sector', $profile->urlParams()),
                                'subject' => 'o alerta “'.$profile->displayName().'”', 'short' => 'este alerta', 'things' => 'as notícias dele',
                                'sectorChoices' => $manages ? $sectors : collect(), 'sectorless' => $manages && $sectorless,
                                'mutedIn' => $mutedIn[$profile->id] ?? [],
                            ])
                        @endif

                        {{-- Configurar é de quem configura (R14): o membro só vê e vota. --}}
                        @if (in_array($profile->id, $managed, true))
                        <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-ink-850 pt-3 text-sm">
                            @if ($profile->completed_at)
                                <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => 1])) }}" class="text-accent hover:underline">Editar</a>
                                <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => 7])) }}" class="text-accent hover:underline">Prévia</a>
                                <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => 5])) }}" class="text-accent hover:underline">{{ $domain ? 'Domínio' : 'Adicionar domínio' }}</a>
                                <form method="POST" action="{{ route('alerts.pause', $profile->urlParams()) }}">
                                    @csrf
                                    <button class="text-ink-200 hover:text-white">{{ $profile->isPaused() ? 'Retomar' : 'Pausar' }}</button>
                                </form>
                            @else
                                <a href="{{ route('alerts.interview', $profile->urlParams(['passo' => $profile->step_reached])) }}" class="font-semibold text-accent hover:underline">
                                    Continuar a entrevista
                                </a>
                            @endif
                            <form method="POST" action="{{ route('alerts.destroy', $profile->urlParams()) }}" class="ml-auto"
                                  data-confirm="Excluir o alerta “{{ $profile->displayName() }}”? Respostas, domínio e histórico dele serão apagados.">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-300 hover:text-red-200">Excluir</button>
                            </form>
                        </div>
                        @endif
                    </section>
                @endforeach

                <section class="flex flex-col gap-1 rounded-xl border border-ink-800 bg-ink-900 p-5">
                    <p class="text-[11px] tracking-widest text-ink-400 uppercase">Onde você recebe{{ $many ? ' · todos os alertas' : '' }}</p>
                    @forelse ($channels as $item)
                        <div class="flex items-start gap-2.5 py-1">
                            <x-brand-icon :name="$item->type" class="mt-0.5 size-6" />
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-white">{{ $item->appName() }} · {{ $item->label() }}</p>
                                <p class="text-sm text-ink-400">
                                    @if ($item->status !== 'active')
                                        {{ $item->status === 'pending' ? $item->pendingLabel() : ($item->isWebhook() ? 'envio parado' : 'precisa reconectar') }}
                                    @elseif ($item->isPaused())
                                        <span class="text-amber-200">envio pausado</span>
                                    @else
                                        Recebe {{ $item->typesLabel() }} ·
                                        Na hora: {{ $item->realtime ? 'ligado' : 'desligado' }} ·
                                        Resumo: {{ $item->digest ? $item->digest_time.', '.$item->daysLabel() : 'desligado' }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="font-semibold text-white">Nenhum canal conectado</p>
                        <p class="text-sm text-ink-400">Os alertas ficam só aqui por enquanto.</p>
                    @endforelse
                    <p class="mt-2 text-sm">
                        <a href="{{ route('alerts.notifiers') }}" class="text-accent hover:underline">{{ $channels->isEmpty() ? 'Escolher onde receber' : 'Configurar em Notificadores' }}</a>
                    </p>
                </section>
            </div>

            @if ($matches)
                <section class="overflow-hidden rounded-xl border border-ink-800 bg-ink-900">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-800 px-5 py-3">
                        <div>
                            <h2 class="font-semibold text-white">Notícias recebidas{{ $only ? ' · '.$only->displayName() : '' }}</h2>
                            <p class="text-sm text-ink-400">
                                Últimos 30 dias: {{ $stats['total'] }} {{ $stats['total'] === 1 ? 'alerta' : 'alertas' }}
                                @if ($stats['by_level']->isNotEmpty())
                                    ({{ $stats['by_level']->map(fn ($n, $level) => $n.' '.($level ?: 'sem grau'))->implode(', ') }})
                                @endif
                                · {{ $stats['irrelevant'] }} marcado(s) como não relevante
                                · <span class="text-violet-300">{{ $stats['held'] }} segurado(s) pela IA</span>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($completed->count() > 1)
                                <nav aria-label="Alerta" class="flex flex-wrap gap-1 rounded-lg border border-ink-800 p-1">
                                    @foreach ([null => 'Todos os alertas'] + $completed->mapWithKeys(fn ($p) => [$p->id => $p->displayName()])->all() as $id => $label)
                                        <a href="{{ route('alerts.news', array_filter(['alerta' => $id, 'filtro' => $filter === 'todos' ? null : $filter])) }}"
                                           @if (($only?->id) === ($id ?: null)) aria-current="page" @endif
                                           class="inline-flex min-h-9 items-center rounded-md px-3 text-sm {{ ($only?->id) === ($id ?: null) ? 'bg-ink-800 text-white' : 'text-ink-400 hover:text-ink-200' }}">{{ $label }}</a>
                                    @endforeach
                                </nav>
                            @endif
                            @include('alerts.partials.filters', [
                                'filters' => AlertPanel::FILTERS,
                                'current' => $filter,
                                'counts' => $counts,
                                'link' => fn ($value) => route('alerts.news', array_filter(['filtro' => $value === 'todos' ? null : $value, 'alerta' => $only?->id])),
                            ])
                        </div>
                    </div>

                    @forelse ($matches as $match)
                        @include('alerts.partials.news-row', ['match' => $match, 'many' => $completed->count() > 1])
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-ink-400">
                            @if ($filter === 'pendentes')
                                Nenhum alerta pendente: tudo o que a IA deixou passar já foi avaliado.
                            @elseif ($filter === 'relevantes')
                                Nenhum alerta relevante ainda. Aqui entram os que a IA aprovou e os que você marcar como relevante.
                            @elseif ($filter === 'segurados-pela-ia')
                                A IA não segurou nenhum alerta. Quando ela julgar que uma notícia não interessa a você, ela aparece aqui, e você pode marcar como relevante.
                            @else
                                Nenhuma notícia {{ $filter === 'todos' ? 'ainda' : 'neste filtro' }}. Assim que uma notícia casar com um dos seus alertas, ela aparece aqui.
                            @endif
                        </p>
                    @endforelse

                    @if ($matches->hasPages())
                        <div class="border-t border-ink-850 px-5 py-3 text-sm">{{ $matches->links() }}</div>
                    @endif
                </section>
            @endif
        </div>
    @endif
@endsection
