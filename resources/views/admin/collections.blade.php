@extends('layouts.admin')

@section('title', 'Coletas')

@php
    $link = fn (array $changes = []) => route('admin.collections', array_filter(array_merge($filters, $changes), fn ($v) => $v !== null && $v !== ''));
    $windows = [1 => '1h', 6 => '6h', 24 => '24h', 168 => '7 dias', 720 => '30 dias'];
@endphp

@section('content')
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-sm font-semibold text-white">Coletas do crawler</h1>
            <p class="text-xs text-ink-700">
                @if ($counters['ultima_coleta'])
                    Última matéria capturada {{ \Carbon\Carbon::parse($counters['ultima_coleta'])->diffForHumans() }}
                @else
                    Nenhuma coleta ainda.
                @endif
                @if ($lastRun)
                    · última execução em {{ $lastRun->started_at?->timezone('America/Sao_Paulo')->format('d/m H:i') }} ({{ $lastRun->trigger }})
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex gap-1 rounded-lg border border-ink-800 bg-ink-900 p-1 text-xs">
                @foreach ($windows as $h => $label)
                    <a href="{{ $link(['window' => (string) $h]) }}"
                       class="rounded px-2.5 py-1 {{ $hours === $h ? 'bg-accent font-medium text-ink-950' : 'text-ink-400 hover:text-ink-200' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-xs text-ink-400">
                <input type="checkbox" data-auto-refresh="30" class="rounded border-ink-700 bg-ink-850 text-accent">
                atualizar sozinho
            </label>

            <form method="POST" action="{{ route('admin.bots.run') }}">
                @csrf
                <input type="hidden" name="force" value="1">
                <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                    Coletar tudo agora
                </button>
            </form>
        </div>
    </div>

    <section class="mb-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            ['Coletados', $counters['coletados'], 'text-white'],
            ['Matérias novas', $counters['materias'], 'text-accent'],
            ['Atualizações', $counters['atualizacoes'], 'text-sky-300'],
            ['Repetições', $counters['repeticoes'], 'text-ink-400'],
            ['Corpo do site', $counters['corpo_completo'], 'text-emerald-300'],
            ['Só resumo do feed', $counters['so_resumo'], 'text-orange-300'],
        ] as [$label, $value, $color])
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-3">
                <p class="mb-0.5 text-[11px] text-ink-700">{{ $label }}</p>
                <p class="font-mono text-xl {{ $color }}">{{ number_format($value, 0, ',', '.') }}</p>
            </div>
        @endforeach
    </section>

    {{-- IA agora: o job do hermes em execução, o que espera por ela e quanto das coletas do período ela já analisou. --}}
    <section class="mb-5 rounded-lg border border-violet-900/50 bg-ink-900 p-4 text-sm">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-xs font-semibold tracking-widest text-violet-300 uppercase">IA agora</h2>
            <p class="text-xs text-ink-400">
                <a href="{{ route('admin.queue') }}" class="hover:text-accent">ver a fila →</a>
                @if ($ai['failed'])
                    · <a href="{{ route('admin.queue', ['ver' => 'falhos']) }}" class="text-red-300 hover:underline">{{ $ai['failed'] }} job(s) falho(s)</a>
                @endif
            </p>
        </div>

        <div class="mb-3">
            <div class="mb-1 flex justify-between text-xs">
                <span class="text-ink-400">Coletas do período analisadas</span>
                <span class="font-mono text-white">
                    @if ($ai['percent'] === null)
                        —
                    @else
                        {{ $ai['percent'] }}% <span class="text-ink-700">· {{ number_format($ai['analyzed'], 0, ',', '.') }} de {{ number_format($ai['collected'], 0, ',', '.') }}</span>
                    @endif
                </span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-ink-850">
                <div class="h-full rounded-full bg-violet-400" style="width: {{ $ai['percent'] ?? 0 }}%"></div>
            </div>
        </div>

        @forelse ($ai['running'] as $job)
            @php
                $elapsed = (int) $job['running_since']->diffInSeconds(now(), true);
                $share = $job['timeout'] ? min(100, (int) floor($elapsed / $job['timeout'] * 100)) : null;
            @endphp
            <div class="mt-2 rounded border border-ink-800 bg-ink-950/40 px-3 py-2">
                <p class="flex flex-wrap items-baseline justify-between gap-2">
                    <span><span class="text-emerald-300">●</span> <span class="text-white">{{ $job['label'] }}</span>
                        <span class="font-mono text-[11px] text-ink-700">{{ $job['class'] }}</span></span>
                    <span class="font-mono text-xs text-ink-400">
                        há {{ $job['running_since']->diffForHumans(now(), true) }}
                        @if ($share !== null)<span class="text-ink-700">· {{ $share }}% do limite de {{ intdiv($job['timeout'], 60) }} min</span>@endif
                    </span>
                </p>
                @foreach ($job['subjects'] as $subject)
                    <p class="truncate text-xs text-ink-400">
                        @if ($subject['url'])<a href="{{ $subject['url'] }}" target="_blank" class="hover:text-accent">{{ $subject['text'] }} ↗</a>@else{{ $subject['text'] }}@endif
                    </p>
                @endforeach
                @if ($step = $job['review'] ?? null)
                    <p class="text-xs text-violet-300">
                        Revisor do relatório:
                        {{ $step['step'] === 'parecer' ? 'parecer '.$step['round'].' de até '.$step['rounds'] : 'a análise refaz o ajuste '.$step['round'] }}
                    </p>
                @endif
                @if ($share !== null)
                    <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-ink-850">
                        <div class="h-full {{ $share >= 80 ? 'bg-amber-400' : 'bg-emerald-400' }}" style="width: {{ $share }}%"></div>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-xs text-ink-400">Nenhum job de IA rodando agora.</p>
        @endforelse

        {{-- Revisor do relatório: chamadas a mais na mesma vaga da análise, e as matérias com revisão pendente. --}}
        <p class="mt-3 text-xs text-ink-400">
            <span class="text-ink-700">Revisor do relatório:</span>
            @if (! $review['on'])
                desligado <a href="{{ route('admin.prompts.show', 'revisor') }}" class="text-ink-700 hover:text-accent">(Prompts)</a>@if ($review['approved'] + $review['pending'] + $review['skipped'] > 0) ·@endif
            @endif
            @if ($review['approved'] + $review['pending'] + $review['skipped'] > 0)
                <span class="whitespace-nowrap">{{ $review['approved'] }} aprovada(s)@if ($review['adjusted']) ({{ $review['adjusted'] }} depois de ajuste)@endif</span> ·
                <span class="whitespace-nowrap {{ $review['pending'] ? 'text-amber-300' : '' }}">{{ $review['pending'] }} com revisão pendente</span> ·
                <span class="whitespace-nowrap">{{ $review['skipped'] }} pulada(s)</span> ·
                <span class="whitespace-nowrap">{{ $review['calls'] }} chamada(s) a mais</span>
            @elseif ($review['on'])
                nada revisado no período
            @endif
        </p>
        @if ($review['open_total'] > 0)
            <p class="mt-1 text-xs text-amber-300">
                Revisão pendente ({{ $review['open_total'] }}):
                @foreach ($review['open'] as $open)
                    <a href="{{ route('feed.show', $open['uuid']) }}" target="_blank" class="hover:underline">{{ \Illuminate\Support\Str::limit($open['title'], 60) }} ↗</a>@if (! $loop->last) · @endif
                @endforeach
            </p>
        @endif

        @if ($ai['waiting']->isNotEmpty())
            <p class="mt-3 text-xs text-ink-400">
                <span class="text-ink-700">Esperando a IA:</span>
                @foreach ($ai['waiting'] as $label => $total)
                    <span class="whitespace-nowrap">{{ $label }} <span class="font-mono text-ink-200">{{ $total }}</span></span>@if (! $loop->last) · @endif
                @endforeach
            </p>
        @endif
    </section>

    @if ($counters['sem_ia'] > 0)
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3 rounded-lg border border-red-900/60 bg-red-950/20 px-4 py-3 text-sm">
            <div class="min-w-0">
                <p class="text-red-300">
                    {{ $counters['sem_ia'] }} artigo(s) viraram matéria sem a análise da IA: ela falhou e entrou a extração heurística.
                    <a href="{{ $link(['status' => 'sem_ia']) }}" class="underline">ver quais</a>
                </p>
                <ul class="mt-1 space-y-0.5 text-[11px] text-ink-400">
                    @foreach ($counters['sem_ia_motivos'] as $row)
                        <li class="truncate" title="{{ $row->reason }}"><span class="font-mono text-ink-200">{{ $row->total }}×</span> {{ $row->clear ?? $row->reason }}</li>
                    @endforeach
                </ul>
            </div>
            <form method="POST" action="{{ route('admin.articles.rebuild-ai') }}"
                  data-confirm="Refazer com a IA as matérias desses {{ $counters['sem_ia'] }} artigo(s)? Cada matéria é reescrita no lugar, com o mesmo link; se a IA falhar de novo, o erro volta a aparecer aqui.">
                @csrf
                <button class="shrink-0 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                    Refazer com a IA
                </button>
            </form>
        </div>
    @endif

    @if ($counters['pendentes'] > 0)
        <div class="mb-5 rounded-lg border border-orange-900/60 bg-orange-950/20 px-4 py-2.5 text-sm text-orange-300">
            {{ $counters['pendentes'] }} artigo(s) coletado(s) ainda não viraram matéria —
            <a href="{{ route('admin.runs') }}" class="underline">ver na aba Execuções</a>.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">

        {{-- Linha do tempo das coletas --}}
        <section>
            <div class="mb-3 flex flex-wrap items-center gap-1.5 text-xs">
                <a href="{{ $link(['status' => null, 'extraction' => null, 'source' => null]) }}"
                   class="rounded-md border px-2.5 py-1 {{ empty($filters['status']) && empty($filters['extraction']) && empty($filters['source']) ? 'border-accent bg-accent/10 text-accent' : 'border-ink-800 text-ink-400 hover:text-ink-200' }}">
                    tudo
                </a>
                @foreach ([['status', 'enriched', 'analisadas'], ['status', 'no_update', 'repetições'], ['status', 'pending', 'na fila'], ['status', 'failed', 'com erro'], ['status', 'sem_ia', 'IA falhou'], ['status', 'fora_do_feed', 'fora do feed'], ['extraction', 'summary', 'só resumo do feed'], ['extraction', 'manual', 'texto colado']] as [$key, $value, $label])
                    <a href="{{ $link([$key => $value]) }}"
                       class="rounded-md border px-2.5 py-1 {{ ($filters[$key] ?? null) === $value ? 'border-accent bg-accent/10 text-accent' : 'border-ink-800 text-ink-400 hover:text-ink-200' }}">
                        {{ $label }}
                    </a>
                @endforeach
                @if (! empty($filters['source']))
                    <span class="rounded-md border border-accent bg-accent/10 px-2.5 py-1 font-mono text-accent">
                        {{ $filters['source'] }}
                        <a href="{{ $link(['source' => null]) }}" class="ml-1 opacity-60 hover:opacity-100">&times;</a>
                    </span>
                @endif
            </div>

            <div class="divide-y divide-ink-900 rounded-lg border border-ink-800">
                @forelse ($articles as $article)
                    @php
                        $badge = match ($article->status) {
                            'enriched' => $article->is_first
                                ? ['matéria nova', 'bg-accent/15 text-accent']
                                : ['atualização', 'bg-sky-950/60 text-sky-300'],
                            'no_update' => ['repetição', 'bg-ink-850 text-ink-400'],
                            'failed' => ['erro', 'bg-red-950/60 text-red-300'],
                            default => ['na fila', 'bg-orange-950/50 text-orange-300'],
                        };
                    @endphp

                    <article class="flex gap-3 px-3 py-2.5 hover:bg-ink-900/50">
                        <time class="w-24 shrink-0 pt-0.5 font-mono text-[11px] text-ink-700"
                              datetime="{{ optional($article->collected_at)->toIso8601String() }}">
                            {{ $article->collected_at?->timezone('America/Sao_Paulo')->format('d/m H:i:s') }}
                        </time>

                        <div class="min-w-0 flex-1">
                            <div class="mb-0.5 flex flex-wrap items-center gap-1.5 text-[11px]">
                                <a href="{{ $link(['source' => $article->source_host]) }}"
                                   class="font-mono text-ink-400 hover:text-accent">{{ $article->source_host }}</a>
                                <span class="rounded px-1.5 py-px {{ $badge[1] }}">{{ $badge[0] }}</span>
                                @if ($article->extraction === 'manual')
                                    <span class="rounded bg-emerald-950/50 px-1.5 py-px text-emerald-300"
                                          title="texto completo colado no admin">texto colado</span>
                                @elseif ($article->extraction !== 'full')
                                    <span class="rounded bg-orange-950/40 px-1.5 py-px text-orange-300"
                                          title="{{ $article->error ?: 'o site não entregou o corpo da matéria' }}">
                                        só resumo do feed
                                    </span>
                                @endif
                                @if ($article->criticity)
                                    <x-criticity :level="$article->criticity" size="xs" />
                                @endif
                                <span class="text-ink-700">{{ number_format($article->content_chars, 0, ',', '.') }} chars</span>
                            </div>

                            <p class="truncate text-sm text-ink-200">
                                {{ $article->title ?: $article->original_title }}
                            </p>

                            @if ($situation = $situations[$article->id] ?? null)
                                @include('admin._attempt-situation', ['situation' => $situation, 'article' => $article])
                            @endif
                        </div>

                        <div class="flex shrink-0 items-start gap-2 pt-0.5 text-[11px]">
                            @if ($article->newsCase)
                                @unless ($article->newsCase->is_published)
                                    <span class="rounded bg-amber-950/50 px-1.5 py-px text-amber-300" title="só o admin vê a matéria">fora do feed</span>
                                @endunless
                                <a href="{{ route('feed.show', $article->newsCase->uuid) }}" target="_blank"
                                   class="text-accent hover:underline">matéria</a>
                            @endif
                            <a href="{{ $article->url }}" target="_blank" rel="noopener noreferrer"
                               class="text-ink-700 hover:text-ink-200">fonte ↗</a>
                            @if (in_array($article->extraction, ['summary', 'empty', 'manual'], true))
                                <a href="{{ route('admin.articles.text', $article) }}"
                                   class="text-amber-200 hover:underline">{{ $article->extraction === 'manual' ? 'editar texto' : 'colar texto completo' }}</a>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="px-4 py-10 text-center text-sm text-ink-700">
                        Nada coletado nesta janela. Use <strong>Coletar tudo agora</strong> ou aumente o período.
                    </p>
                @endforelse
            </div>

            <div class="mt-4">{{ $articles->links() }}</div>
        </section>

        {{-- Saúde por fonte --}}
        <aside class="lg:sticky lg:top-4 lg:self-start">
            <h2 class="mb-2 text-[11px] font-semibold tracking-widest text-ink-700 uppercase">Saúde das fontes</h2>
            <p class="mb-3 text-[11px] text-ink-700">
                % = matérias em que o corpo veio do site. Abaixo disso, entra o resumo do feed
                (site com WAF ou página montada por JavaScript).
            </p>

            <ul class="divide-y divide-ink-900 rounded-lg border border-ink-800">
                @foreach ($sources as $row)
                    @php
                        $rate = $row['rate'];
                        $color = match (true) {
                            $rate === null => 'text-ink-700',
                            $rate >= 80 => 'text-emerald-400',
                            $rate >= 40 => 'text-orange-300',
                            default => 'text-red-400',
                        };
                    @endphp
                    <li class="px-3 py-2">
                        <div class="flex items-baseline justify-between gap-2">
                            <a href="{{ $link(['source' => $row['source']->host]) }}"
                               class="truncate font-mono text-[12px] {{ $row['source']->enabled ? 'text-ink-200' : 'text-ink-700 line-through' }} hover:text-accent">
                                {{ $row['source']->host }}
                            </a>
                            <span class="shrink-0 font-mono text-[11px] {{ $color }}">
                                {{ $rate === null ? '—' : $rate.'%' }}
                            </span>
                        </div>
                        <div class="mt-0.5 flex justify-between text-[10px] text-ink-700">
                            <span>{{ $row['total'] }} artigos</span>
                            <span>{{ $row['last_at'] ? \Carbon\Carbon::parse($row['last_at'])->diffForHumans(short: true) : 'nunca' }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </aside>
    </div>
@endsection
