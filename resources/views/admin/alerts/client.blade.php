@extends('layouts.admin')

@section('title', 'Alertas · '.($profile?->company_name ?: $user->name))

@php
    $box = 'rounded-lg border border-ink-800 bg-ink-900';
    $steps = \App\Models\InterviewQuestion::STEPS;
    $sourceStyle = ['interview' => 'border-ink-700 text-ink-400', 'discovery' => 'border-sky-800 text-sky-300', 'admin' => 'border-accent/50 text-amber-200'];
    $sourceLabel = ['interview' => 'entrevista', 'discovery' => 'domínio', 'admin' => 'admin'];
    $resultStyle = ['sent' => 'text-emerald-300', 'held' => 'text-amber-200', 'failed' => 'text-red-300'];
@endphp

@section('content')
    @include('admin.alerts._nav')

    <p class="mb-2 text-xs text-ink-400"><a href="{{ route('admin.alerts.clients') }}" class="hover:text-ink-200">Clientes</a> / {{ $profile?->company_name ?: $user->name }}</p>

    @if ($profiles->count() > 1)
        {{-- Um alerta por produto ou site: as ações abaixo valem para o alerta selecionado. --}}
        <nav aria-label="Alertas do cliente" class="mb-4 flex flex-wrap gap-1 rounded-lg border border-ink-800 p-1 text-sm">
            @foreach ($profiles as $p)
                <a href="{{ route('admin.alerts.client', [$user, 'alerta' => $p->id]) }}" @if ($p->is($profile)) aria-current="page" @endif
                   class="inline-flex min-h-9 items-center gap-1.5 rounded-md px-3 {{ $p->is($profile) ? 'bg-ink-800 text-white' : 'text-ink-400 hover:text-ink-200' }}">
                    {{ $p->displayName() }}
                    <span class="text-xs text-ink-400">{{ ! $p->completed_at ? 'rascunho' : ($p->isPaused() ? 'pausado' : 'ativo') }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-white">{{ $profile?->company_name ?: $user->name }}</h2>
            <p class="text-sm text-ink-400">
                {{ $user->name }} · {{ $user->email }}
                @if ($profile?->completed_at) · perfil {{ $profile->isPaused() ? 'pausado' : 'ativo' }} desde {{ $profile->completed_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}
                @elseif ($profile) · entrevista em rascunho, passo {{ $profile->step_reached }}
                @else · ainda não começou a entrevista @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($channels->contains(fn ($c) => $c->status !== 'pending'))
                <form method="POST" action="{{ route('admin.alerts.client.test', $user) }}">@csrf
                    <button class="rounded-lg border border-accent px-3 py-2 text-sm text-amber-200 hover:bg-accent/10">Enviar teste nos canais</button>
                </form>
            @endif
            @if ($profile)
                <form method="POST" action="{{ route('admin.alerts.client.recompile', [$user, 'alerta' => $profile?->id]) }}">@csrf
                    <button class="rounded-lg border border-ink-800 px-3 py-2 text-sm text-ink-200 hover:border-ink-700">Recompilar perfil</button>
                </form>
            @endif
            @if ($profile?->completed_at)
                <form method="POST" action="{{ route('admin.alerts.client.pause', [$user, 'alerta' => $profile?->id]) }}">@csrf
                    <button class="rounded-lg border border-ink-800 px-3 py-2 text-sm text-ink-200 hover:border-ink-700">{{ $profile->isPaused() ? 'Retomar alertas' : 'Pausar alertas' }}</button>
                </form>
            @endif
        </div>
    </div>

    @if ($profile)
        <div class="mb-6 grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
            <div class="space-y-4">
                <section class="{{ $box }} overflow-hidden">
                    <h3 class="border-b border-ink-800 px-4 py-3 text-sm font-semibold text-white">Respostas da entrevista</h3>
                    <dl class="text-sm">
                        @forelse ($answers as $step => $items)
                            @continue($items->isEmpty())
                            <div class="flex flex-wrap gap-x-3 gap-y-1 border-t border-ink-850 px-4 py-2.5 first:border-t-0">
                                <dt class="w-32 shrink-0 text-ink-400">{{ $steps[$step][0] ?? $step }}</dt>
                                <dd class="min-w-0 flex-1 text-ink-200">
                                    @foreach ($items as $a)
                                        <span class="text-ink-400">{{ rtrim($a['prompt'], '?') }}:</span> {{ $a['answer'] }}@if (! $loop->last)<span class="text-ink-700"> · </span>@endif
                                    @endforeach
                                </dd>
                            </div>
                        @empty
                            <p class="px-4 py-4 text-ink-400">Sem respostas.</p>
                        @endforelse
                    </dl>
                </section>

                <section class="{{ $box }} space-y-3 p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold text-white">Tecnologias ({{ $profile->techs->count() }}) e termos livres ({{ $profile->terms->count() }})</h3>
                        <span class="text-xs text-ink-400">origem: entrevista · domínio · admin</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($profile->techs->sortBy('name') as $tech)
                            <form method="POST" action="{{ route('admin.alerts.client.tech', [$user, 'alerta' => $profile?->id]) }}" class="inline-flex">
                                @csrf
                                <input type="hidden" name="tech_id" value="{{ $tech->id }}">
                                <input type="hidden" name="action" value="remove">
                                <span class="inline-flex items-center gap-1.5 rounded-full border bg-ink-850 py-0.5 pr-1 pl-2.5 text-xs {{ $sourceStyle[$tech->pivot->source] ?? '' }}">
                                    <span class="text-ink-200">{{ $tech->name }}@if (filled($profile->tech_versions[$tech->slug] ?? null)) <span class="font-mono">{{ $profile->tech_versions[$tech->slug] }}</span>@endif</span>{{ $sourceLabel[$tech->pivot->source] ?? $tech->pivot->source }}{{ $tech->strict ? ' · só produto' : '' }}
                                    <button class="grid size-5 place-items-center rounded-full text-ink-400 hover:text-red-300" aria-label="Remover {{ $tech->name }}">&times;</button>
                                </span>
                            </form>
                        @endforeach
                        @foreach ($profile->terms as $term)
                            <span class="rounded-full border border-dashed border-ink-700 px-2.5 py-0.5 text-xs text-ink-200">“{{ $term->value }}”</span>
                        @endforeach
                    </div>
                    <form method="POST" action="{{ route('admin.alerts.client.tech', [$user, 'alerta' => $profile?->id]) }}" class="flex flex-wrap gap-2">
                        @csrf
                        <input type="hidden" name="action" value="add">
                        <select name="tech_id" aria-label="Tecnologia" class="min-w-0 flex-1 rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm [color-scheme:dark]">
                            @foreach ($catalog as $tech)
                                <option value="{{ $tech->id }}">{{ $tech->name }}</option>
                            @endforeach
                        </select>
                        <button class="rounded-lg border border-dashed border-ink-700 px-3 py-2 text-sm text-ink-200 hover:border-ink-400">+ Adicionar como admin</button>
                    </form>
                </section>
            </div>

            <div class="space-y-4">
                <section class="{{ $box }} space-y-3 p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold text-white">Query compilada · v{{ $profile->query_version }}</h3>
                        <span class="text-xs text-ink-400">{{ $profile->percolated_at ? 'no percolator às '.$profile->percolated_at->timezone('America/Sao_Paulo')->format('d/m H:i') : 'fora do percolator' }}</span>
                    </div>
                    <pre class="max-h-72 overflow-auto rounded-lg border border-ink-800 bg-ink-950 p-3 font-mono text-xs leading-5 whitespace-pre-wrap text-ink-200">{{ $profile->compiled_query ?: 'sem termos: o perfil não procura nada' }}</pre>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        @if ($profile->compiled_query)
                            <a href="{{ route('feed.index', ['q' => $profile->compiled_query]) }}" class="text-accent hover:underline" target="_blank">Testar no feed ↗</a>
                        @endif
                        @if ($preview)
                            <span class="text-ink-200">Prévia: {{ $preview['error'] ?? $preview['total'].' alertas nos últimos 30 dias' }}</span>
                        @else
                            <a href="{{ route('admin.alerts.client', [$user, 'previa' => 1, 'alerta' => $profile?->id]) }}" class="text-ink-400 hover:text-ink-200">Rodar prévia de 30 dias</a>
                        @endif
                    </div>
                </section>

                <section class="{{ $box }} space-y-1 p-4 text-sm">
                    <h3 class="mb-1 font-semibold text-white">Canais</h3>
                    @forelse ($channels as $channel)
                        <div class="{{ $loop->first ? '' : 'border-t border-ink-800 pt-2' }} space-y-1">
                            <p><span class="{{ $channel->status === 'active' ? 'text-emerald-300' : ($channel->status === 'pending' ? 'text-amber-200' : 'text-red-300') }}">{{ ['active' => 'ativo', 'pending' => 'falta escolher o canal'][$channel->status] ?? 'precisa reconectar' }}</span>
                                @if ($channel->isPaused()) · <span class="text-amber-200">envio pausado desde {{ $channel->paused_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</span> @endif
                                · {{ $channel->appName() }} {{ $channel->methodLabel() }} · {{ $channel->label() }}</p>
                            <p class="text-ink-400">
                                Recebe: {{ $channel->typesLabel() }}
                                · Na hora: {{ $channel->realtime ? 'ligado' : 'desligado' }}{{ $channel->quiet_start ? ', silêncio '.$channel->quiet_start.'–'.$channel->quiet_end : '' }}
                                · Resumo: {{ $channel->digest ? $channel->digest_time.' '.$channel->daysLabel().', '.($channel->digest_scope === 'all' ? 'tudo' : 'só o perfil') : 'desligado' }} · {{ $channel->timezone }}
                            </p>
                            @if ($channel->last_error) <p class="text-red-300">{{ $channel->last_error }}</p> @endif
                        </div>
                    @empty
                        <p class="text-ink-400">Sem canal conectado: os alertas ficam só em Alertas.</p>
                    @endforelse
                </section>

                @foreach ($profile->domains as $domain)
                    <section class="{{ $box }} space-y-1 p-4 text-sm">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="font-semibold text-white">Domínio</h3>
                            @if (Route::has('admin.alerts.domains'))
                                <a href="{{ route('admin.alerts.domains', ['log' => $domain->id]) }}" class="text-xs text-accent hover:underline">Ver achados e log</a>
                            @endif
                        </div>
                        <p><span class="font-mono">{{ $domain->domain }}</span> ·
                            <span class="{{ $domain->verified_at ? 'text-emerald-300' : 'text-amber-200' }}">{{ $domain->verified_at ? 'verificado em '.$domain->verified_at->timezone('America/Sao_Paulo')->format('d/m H:i') : 'aguardando TXT' }}</span></p>
                        <p class="text-ink-400">
                            {{ $domain->consent_at ? 'Aceite '.$domain->consent_version.' em '.$domain->consent_at->timezone('America/Sao_Paulo')->format('d/m H:i').', IP '.$domain->consent_ip : 'Sem aceite para a descoberta' }}
                            · descoberta {{ $domain->discovery_status }} · {{ $domain->findings->count() }} achados
                        </p>
                    </section>
                @endforeach
            </div>
        </div>

        <section class="{{ $box }} mb-6 overflow-hidden">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-ink-800 px-4 py-3">
                <h3 class="text-sm font-semibold text-white">Casos que casaram com o perfil</h3>
                <span class="text-xs text-ink-400">inclui os suprimidos, para calibrar</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-left text-sm">
                    <thead class="text-[11px] tracking-widest text-ink-400 uppercase">
                        <tr><th class="px-4 py-2 font-medium">Quando</th><th class="px-3 py-2 font-medium">Caso</th><th class="px-3 py-2 font-medium">Nível</th><th class="px-3 py-2 font-medium">Motivos</th><th class="px-3 py-2 text-right font-medium">Score</th><th class="px-3 py-2 font-medium">Resultado</th><th class="px-4 py-2 font-medium">Feedback</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($matches as $match)
                            <tr class="border-t border-ink-850">
                                <td class="px-4 py-2.5 whitespace-nowrap text-ink-400">{{ $match->matched_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                                <td class="px-3 py-2.5"><a href="{{ route('feed.show', $match->case->uuid) }}" class="text-white hover:text-accent">{{ $match->case->title_pt ?: $match->case->title }}</a></td>
                                <td class="px-3 py-2.5"><x-criticity :level="$match->criticity" size="xs" /></td>
                                <td class="px-3 py-2.5 font-mono text-xs">
                                    {{ collect($match->reasons)->map(fn ($r) => $r['field'].':'.$r['label'])->implode(', ') }}
                                </td>
                                <td class="px-3 py-2.5 text-right">{{ $match->score }}</td>
                                <td class="px-3 py-2.5 {{ $match->suppressed_reason ? 'text-ink-400' : ($resultStyle[$match->delivery_status] ?? 'text-ink-200') }}">
                                    {{ $match->suppressed_reason ? 'suprimido: '.$match->suppressedLabel() : $match->deliveryLabel($channels->map->appName()->implode(' e ') ?: 'Slack') }}
                                    @if ($verdict = $match->aiVerdict())
                                        <span class="mt-0.5 block max-w-xs text-xs text-ink-400">{{ $verdict }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-ink-400">
                                    {{ ['relevant' => 'relevante', 'irrelevant' => 'não relevante'][$match->feedback] ?? '—' }}{{ $match->feedback_unaffected ? ' · não atinge (protegido)' : '' }}
                                    @if ($match->feedback_note)
                                        <span class="mt-0.5 block max-w-xs text-xs {{ $match->feedback_note_flagged ? 'text-red-300' : 'text-ink-200' }}"
                                              title="{{ $match->feedback_note_flagged ? 'Parece instrução para a IA: não vai para o modelo.' : 'Lido pela IA nos próximos pareceres.' }}">“{{ $match->feedback_note }}”{{ $match->feedback_note_flagged ? ' · fora da IA' : '' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-ink-400">Nenhum caso casou ainda.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($matches->hasPages())
                <div class="border-t border-ink-850 px-4 py-3">{{ $matches->links() }}</div>
            @endif
        </section>
    @endif

    @if ($deliveries->isNotEmpty())
        <section class="{{ $box }} overflow-hidden">
            <div class="flex items-baseline justify-between border-b border-ink-800 px-4 py-3">
                <h3 class="text-sm font-semibold text-white">Últimos envios</h3>
                <a href="{{ route('admin.alerts.deliveries', ['q' => $user->email]) }}" class="text-xs text-accent hover:underline">Ver todos</a>
            </div>
            <table class="w-full text-left text-sm">
                <tbody>
                    @foreach ($deliveries as $d)
                        <tr class="border-t border-ink-850 first:border-t-0">
                            <td class="px-4 py-2 whitespace-nowrap text-ink-400">{{ $d->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $d->channel?->appName() }} · {{ $d->kindLabel() }}</td>
                            <td class="px-3 py-2 text-white">{{ $d->summary }}</td>
                            <td class="px-4 py-2 {{ ['sent' => 'text-emerald-300', 'failed' => 'text-red-300'][$d->status] ?? 'text-ink-400' }}">{{ $d->statusLabel() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
@endsection
