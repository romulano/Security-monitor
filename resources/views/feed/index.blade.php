@extends('layouts.app')

@section('title', $query ? "Busca: {$query} · Security Monitor" : 'Security Monitor · feed de ameaças')

@php
    use App\Support\QueryTokens;
    use App\Support\Markdown;
    use Illuminate\Support\HtmlString;

    $feedUrl = fn (string $q, int $page = 1) => route('feed.index', array_filter([
        'q' => $q ?: null,
        'page' => $page > 1 ? $page : null,
    ]));

    // Os links de busca levam nofollow: cada combinação de filtros é um endereço novo, e o robô da busca de
    // 2026-11-01 percorria milhares. O que acrescenta filtro vai só até QueryTokens::MAX_FILTERS (acima, o <a>
    // sai sem endereço); tirar um filtro, voltar e paginar sempre têm link.
    $searchLink = fn (string $q, int $page = 1) => new HtmlString('href="'.e($feedUrl($q, $page)).'"'.($q !== '' ? ' rel="nofollow"' : ''));
    $facetLink = fn (string $q) => QueryTokens::filterCount($q) > QueryTokens::MAX_FILTERS
        ? new HtmlString('aria-disabled="true" title="Até '.QueryTokens::MAX_FILTERS.' filtros por busca"')
        : $searchLink($q);

    // Título dos grupos de filtro: no celular vira um botão que abre e fecha o grupo.
    $collapseButton = 'group flex w-full items-center justify-between gap-2 text-left max-lg:min-h-12 lg:pointer-events-none lg:mb-2';
    $railTitle = 'text-[11px] font-semibold tracking-widest text-ink-700 uppercase';
    $chevron = '<svg class="size-4 shrink-0 text-ink-700 transition group-aria-expanded:rotate-180 lg:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
@endphp

@section('content')
    @if ($result['error'])
        <div class="mb-5 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-3 text-sm text-red-300">
            {{ $result['error'] }}
        </div>
    @endif

    {{-- Chips da consulta: o estado do filtro fica sempre visível e removível. --}}
    @if ($chips)
        <div class="mb-5 flex flex-wrap items-center gap-2">
            @foreach ($chips as $chip)
                @if ($chip['is_operator'])
                    <span class="font-mono text-xs tracking-widest text-ink-700">{{ mb_strtoupper($chip['token']) }}</span>
                @else
                    <a {{ $searchLink(QueryTokens::removeToken($query, $chip['token'])) }}
                       class="group inline-flex items-center gap-1.5 rounded-full border px-3 py-1 font-mono text-xs
                              {{ $chip['is_field'] ? 'border-accent/40 bg-accent/10 text-accent' : 'border-ink-800 bg-ink-900 text-ink-400' }}">
                        {{ $chip['token'] }}
                        <span class="opacity-50 group-hover:opacity-100">&times;</span>
                    </a>
                @endif
            @endforeach
            <a href="{{ $feedUrl('') }}" class="text-xs text-ink-700 underline underline-offset-2 hover:text-ink-400">limpar</a>
        </div>
    @endif

    <div class="grid gap-8 lg:grid-cols-[15rem_minmax(0,1fr)]">

        {{-- Trilho de filtros. No celular cada grupo começa fechado: abertos, empurravam as notícias para baixo da tela. --}}
        <aside class="lg:sticky lg:top-20 lg:self-start">
            <div class="max-lg:border-b max-lg:border-ink-800 lg:mb-6">
                <button type="button" data-collapse aria-controls="filtro-criticidade" aria-expanded="false" class="{{ $collapseButton }}">
                    <h2 class="{{ $railTitle }}">Criticidade<span class="lg:hidden">{{ $criticity ? ' · '.$criticity : '' }}</span></h2>
                    {!! $chevron !!}
                </button>
                <div id="filtro-criticidade" class="flex flex-wrap gap-1.5 max-lg:hidden max-lg:pb-4">
                    @foreach (config('secmon.criticities') as $level)
                        <a {{ $facetLink(QueryTokens::setCriticity($query, $level)) }}
                           class="rounded-md border px-2.5 py-1 text-xs font-medium capitalize transition
                                  {{ $criticity === $level
                                      ? 'border-accent bg-accent text-ink-950'
                                      : 'border-ink-800 bg-ink-900 text-ink-400 hover:border-ink-700 hover:text-ink-200' }}">
                            {{ $level }}
                        </a>
                    @endforeach
                </div>
            </div>

            @foreach ($result['facets'] as $facet)
                @continue($facet['field'] === 'criticity')
                @php $id = 'facet-'.$facet['field']; @endphp

                <div class="max-lg:border-b max-lg:border-ink-800 lg:mb-6">
                    <button type="button" data-collapse aria-controls="{{ $id }}-body" aria-expanded="false" class="{{ $collapseButton }}">
                        <h2 class="{{ $railTitle }}">{{ $facet['label'] }}</h2>
                        {!! $chevron !!}
                    </button>
                    <div id="{{ $id }}-body" class="max-lg:hidden max-lg:pb-4">
                        <ul class="space-y-px text-sm">
                            @foreach (array_slice($facet['buckets'], 0, 8) as $bucket)
                                @include('feed._facet-item', ['facet' => $facet, 'bucket' => $bucket, 'query' => $query, 'facetLink' => $facetLink])
                            @endforeach
                        </ul>

                        @if (count($facet['buckets']) > 8)
                            <ul id="{{ $id }}" hidden class="space-y-px text-sm">
                                @foreach (array_slice($facet['buckets'], 8) as $bucket)
                                    @include('feed._facet-item', ['facet' => $facet, 'bucket' => $bucket, 'query' => $query, 'facetLink' => $facetLink])
                                @endforeach
                            </ul>
                            <button type="button"
                                    data-toggle-target="#{{ $id }}"
                                    data-label-more="ver mais ({{ count($facet['buckets']) - 8 }})"
                                    data-label-less="ver menos"
                                    class="mt-1.5 text-xs text-accent hover:underline">
                                ver mais ({{ count($facet['buckets']) - 8 }})
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </aside>

        {{-- Feed --}}
        <section>
            <div class="mb-4 flex items-baseline justify-between border-b border-ink-800 pb-2">
                <h1 class="text-sm font-semibold text-white">
                    {{ $query ? 'Resultados' : 'Últimas ameaças' }}
                </h1>
                <p class="font-mono text-xs text-ink-700">
                    {{ number_format($result['total'], 0, ',', '.') }} casos ·
                    pág. {{ $result['page'] }}/{{ max(1, $result['total_pages']) }}
                </p>
            </div>

            @forelse ($result['cases'] as $case)
                <article class="group border-b border-ink-900 py-5 first:pt-0">
                    <div class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-ink-700">
                        <a {{ $facetLink(QueryTokens::toggle($query, 'latest_source_host', $case['latest_source_host'] ?? '')) }}
                           class="font-mono hover:text-accent">{{ $case['latest_source_host'] }}</a>
                        <span>·</span>
                        <time datetime="{{ $case['latest_at'] }}">
                            {{ $case['latest_at'] ? \Carbon\Carbon::parse($case['latest_at'])->diffForHumans(short: true) : '' }}
                        </time>
                        @if (($case['source_count'] ?? 1) > 1)
                            <span>·</span>
                            <span>{{ $case['source_count'] }} fontes</span>
                        @endif
                        @if (($case['update_count'] ?? 1) > 1)
                            <span>·</span>
                            <span>{{ $case['update_count'] }} atualizações</span>
                        @endif
                        <span class="ml-auto flex items-center gap-1.5">
                            @if ($case['has_exploit'] ?? false)
                                <a {{ $facetLink(QueryTokens::toggle($query, 'has_exploit', 'true')) }}
                                   title="Há exploit público para um CVE deste caso"
                                   class="inline-flex items-center rounded border border-rose-800/70 bg-rose-950/40 px-1.5 py-px text-[10px] font-medium text-rose-300 hover:border-rose-600">exploit público</a>
                            @endif
                            <x-criticity :level="$case['criticity']" size="xs" />
                        </span>
                    </div>

                    <h2 class="mb-1.5 text-[17px] leading-snug font-semibold text-white">
                        <a href="{{ route('feed.show', $case['uuid']) }}" class="hover:text-accent">{{ $case['title'] }}</a>
                        @if (($case['report_lang'] ?? null) === 'en')
                            <span class="ml-1 rounded border border-ink-800 px-1 align-middle font-mono text-[10px] font-normal text-ink-700"
                                  title="Sem relatório em português ainda">EN</span>
                        @endif
                    </h2>

                    <p class="mb-2.5 line-clamp-3 text-sm leading-6 text-ink-400">
                        {{ Markdown::toText($case['summary'], 340) }}
                    </p>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach (array_slice($case['cves'] ?? [], 0, 3) as $cve)
                            <a {{ $facetLink(QueryTokens::toggle($query, 'cves', $cve)) }}
                               class="rounded border border-ink-800 bg-ink-900 px-1.5 py-0.5 font-mono text-[11px] text-ink-400 hover:border-accent/50 hover:text-accent">{{ $cve }}</a>
                        @endforeach
                        @foreach (array_slice($case['threat_actors'] ?? [], 0, 2) as $actor)
                            <a {{ $facetLink(QueryTokens::toggle($query, 'threat_actors', $actor)) }}
                               class="rounded border border-ink-800 bg-ink-900 px-1.5 py-0.5 text-[11px] text-ink-400 hover:border-accent/50 hover:text-accent">{{ $actor }}</a>
                        @endforeach
                        @foreach (array_slice($case['victim_sectors'] ?? [], 0, 2) as $sector)
                            <a {{ $facetLink(QueryTokens::toggle($query, 'victim_sectors', $sector)) }}
                               class="rounded border border-ink-800 bg-ink-900 px-1.5 py-0.5 text-[11px] text-ink-400 hover:border-accent/50 hover:text-accent">{{ $sector }}</a>
                        @endforeach
                    </div>
                </article>
            @empty
                {{-- Erro da busca já tem o aviso vermelho lá em cima: não é "nenhum caso". --}}
                @unless ($result['error'])
                    <div class="rounded-lg border border-dashed border-ink-800 px-6 py-16 text-center">
                        @if ($result['total'] > 0)
                            <p class="mb-1 text-sm text-ink-400">Não há resultados nesta página.</p>
                            <p class="text-xs text-ink-700">
                                <a {{ $searchLink($query) }} class="text-accent hover:underline">Voltar à primeira página</a>
                            </p>
                        @elseif ($query)
                            <p class="mb-1 text-sm text-ink-400">Nenhum caso encontrado para essa busca.</p>
                            <p class="text-xs text-ink-700">
                                Tente outros termos ou tire algum filtro.
                                <a href="{{ route('feed.index') }}" class="text-accent hover:underline">Limpar a busca</a>
                            </p>
                        @else
                            <p class="mb-1 text-sm text-ink-400">Ainda não há ameaças publicadas.</p>
                            <p class="text-xs text-ink-700">Os bots coletam das fontes de hora em hora: volte daqui a pouco.</p>
                            @if (auth()->user()?->is_admin)
                                <p class="mt-3 text-xs text-ink-700">
                                    Admin: rode as fontes em <a href="{{ route('admin.sources') }}" class="text-accent hover:underline">Fontes &amp; bots</a>.
                                </p>
                            @endif
                        @endif
                    </div>
                @endunless
            @endforelse

            @if ($result['total_pages'] > 1)
                <nav class="mt-6 flex items-center justify-between text-sm">
                    <a {{ $searchLink($query, $result['page'] - 1) }}
                       class="rounded-md border border-ink-800 px-3 py-1.5 {{ $result['has_prev'] ? 'text-ink-400 hover:text-ink-200' : 'pointer-events-none opacity-30' }}">
                        ← Anterior
                    </a>
                    <span class="font-mono text-xs text-ink-700">
                        {{ $result['start_index'] }}–{{ $result['end_index'] }} de {{ number_format($result['total'], 0, ',', '.') }}
                    </span>
                    <a {{ $searchLink($query, $result['page'] + 1) }}
                       class="rounded-md border border-ink-800 px-3 py-1.5 {{ $result['has_next'] ? 'text-ink-400 hover:text-ink-200' : 'pointer-events-none opacity-30' }}">
                        Próxima →
                    </a>
                </nav>
            @endif
        </section>
    </div>
@endsection
