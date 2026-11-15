@extends('layouts.app')

@section('title', $case['title'].' · Security Monitor')
{{-- O cartão de link e a descrição saem sempre da edição em inglês (a divulgação é em inglês). --}}
@section('og_title', $case['share']['title'])
@section('og_type', 'article')
@section('description', \App\Support\Markdown::toText($case['share']['summary'], 180))

@php
    use App\Support\Markdown;
    use App\Support\QueryTokens;

    $stats = $case['stats'];
    $feedUrl = fn (string $field, string $value) => route('feed.index', ['q' => QueryTokens::token($field, $value)]);
    $exploits = $case['exploits'] ?? [];
    // Sem nenhum crível, o selo fica neutro: há código publicado, mas a IA diz que ele não explora a falha.
    $exploitCredible = collect($exploits)->contains('credible', true);
    $report = $case['report'];
    $reportLang = \App\Support\ReportLanguage::htmlLang($case['report_lang'] ?? 'en');
    $exploitTypes = ['remote' => 'remoto', 'local' => 'local', 'webapps' => 'aplicação web', 'dos' => 'negação de serviço'];
@endphp

@section('content')
    <a href="{{ url()->previous() === url()->current() ? route('feed.index') : url()->previous() }}"
       class="mb-6 inline-flex items-center gap-1.5 text-sm text-ink-700 hover:text-ink-200">
        ← Voltar ao feed
    </a>

    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_17rem]">

        {{-- Leitura --}}
        <article>
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <x-criticity :level="$case['criticity']" />
                @if ($exploits && $exploitCredible)
                    <a href="#exploits"
                       class="rounded border border-rose-800/70 bg-rose-950/40 px-2 py-0.5 text-[11px] font-medium text-rose-300 hover:border-rose-600">exploit público</a>
                @elseif ($exploits)
                    <a href="#exploits" title="Há código publicado para a falha, mas a análise indica que ele não a explora de forma crível."
                       class="rounded border border-ink-800 bg-ink-900 px-2 py-0.5 text-[11px] text-ink-400 hover:border-ink-600">exploit duvidoso</a>
                @endif
                @foreach ($case['victim_sectors'] as $sector)
                    <a rel="nofollow" href="{{ $feedUrl('victim_sectors', $sector) }}"
                       class="rounded border border-ink-800 bg-ink-900 px-2 py-0.5 text-[11px] text-ink-400 hover:border-accent/50 hover:text-accent">{{ $sector }}</a>
                @endforeach
            </div>

            <h1 class="mb-4 text-2xl leading-tight font-semibold text-balance text-white sm:text-[1.75rem]">
                {{ $case['title'] }}
            </h1>

            <div class="mb-8 flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-ink-800 pb-5 text-xs text-ink-700">
                <span class="font-mono">{{ $stats['source_count'] }} fonte(s)</span>
                <span>·</span>
                <span>{{ $stats['new_info_count'] }} atualização(ões)</span>
                @if ($stats['no_update_count'] > 0)
                    <span>·</span>
                    <span>{{ $stats['no_update_count'] }} repetição(ões)</span>
                @endif
                @if ($stats['first_published_at'])
                    <span>·</span>
                    <span>publicado {{ \Carbon\Carbon::parse($stats['first_published_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</span>
                @endif
                @if ($stats['last_collected_at'])
                    <span>·</span>
                    <span>coletado {{ \Carbon\Carbon::parse($stats['last_collected_at'])->diffForHumans() }}</span>
                @endif
            </div>

            {{-- Só o admin: tirar do feed público (fora de segurança), sem apagar, ou devolver. --}}
            @if (auth()->user()?->is_admin)
                @if ($hidden = $case['hidden'] ?? null)
                    <form method="POST" action="{{ route('admin.cases.visibility', $case['uuid']) }}"
                          class="mb-5 flex max-w-[68ch] flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-800/60 bg-amber-950/30 px-3 py-2.5 text-xs leading-5 text-amber-200">
                        @csrf
                        <p>
                            <strong class="font-semibold">Fora do feed público</strong>
                            @if ($hidden['at']) desde {{ \Carbon\Carbon::parse($hidden['at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}@endif:
                            {{ $hidden['reason'] }}. Só o admin vê esta página; para o público ela dá 410.
                        </p>
                        <button class="rounded-md border border-amber-700 px-2.5 py-1 text-amber-100 hover:bg-amber-900/40">Devolver ao feed</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.cases.visibility', $case['uuid']) }}" class="-mt-5 mb-5 text-right"
                          data-confirm="Tirar esta matéria do feed público? Nada é apagado: ela sai da busca, do painel, da API e dos alertas, e você pode devolver aqui mesmo.">
                        @csrf
                        <button class="text-[11px] text-ink-700 underline underline-offset-2 hover:text-ink-400">Admin: tirar do feed público</button>
                    </form>
                @endif

                @if ($review ?? null)
                    @include('feed._review')
                @endif
            @endif

            @if ($merged = $case['consolidated'] ?? null)
                <div class="mb-5 max-w-[68ch] rounded-lg border border-accent/30 bg-accent/5 px-3 py-2.5 text-xs leading-5 text-ink-400">
                    <p>
                        <strong class="font-semibold text-accent">Matéria consolidada.</strong>
                        @if ($merged['stories'] > 1)
                            Reúne {{ $merged['stories'] }} matérias que o Security Monitor tinha publicado separadamente sobre a mesma história.
                        @endif
                        A IA reescreveu o relatório com o que {{ count($merged['sources']) === 1 ? 'a fonte trouxe' : 'as '.count($merged['sources']).' fontes trouxeram' }}
                        em {{ \Carbon\Carbon::parse($merged['at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}; os links antigos trazem para cá.
                    </p>
                    <p class="mt-1.5 flex flex-wrap gap-x-2.5 gap-y-1">
                        <span class="text-ink-700">Fontes:</span>
                        @foreach ($merged['sources'] as $source)
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                               class="font-mono text-accent hover:underline">{{ $source['host'] }}{{ $source['count'] > 1 ? ' ×'.$source['count'] : '' }} ↗</a>
                        @endforeach
                    </p>
                </div>
            @endif

            @if (($case['report_lang'] ?? null) === 'en')
                <p class="mb-5 max-w-[68ch] rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-xs text-ink-400">
                    Esta matéria ainda não tem relatório em português — exibindo a versão em inglês.
                </p>
            @endif

            @if ($case['summary_only'] ?? false)
                <p class="mb-5 max-w-[68ch] rounded-lg border border-amber-700/50 bg-amber-950/30 px-3 py-2 text-xs leading-5 text-amber-200">
                    <strong class="font-semibold">Baseado só no resumo do feed.</strong>
                    O site da fonte não liberou o texto completo para o Security Monitor, então esta análise saiu apenas do resumo
                    publicado no feed e pode estar incompleta. Ela é atualizada quando outra fonte cobrir o assunto.
                    @if ($case['timeline'][0]['news_url'] ?? null)
                        <a href="{{ $case['timeline'][0]['news_url'] }}" target="_blank" rel="noopener noreferrer"
                           class="whitespace-nowrap text-amber-100 underline hover:text-white">Ler na fonte original ↗</a>
                    @endif
                </p>
            @endif

            {{-- Ordem: conteúdo, entenda a falha, exploits, detalhes técnicos, remediação, relatos divergentes, linha do tempo. --}}
            <div class="prose-brief max-w-[68ch]" lang="{{ $reportLang }}">
                {!! Markdown::toHtml($report['body']) !!}
            </div>

            @if ($vulnerability = $case['vulnerability'] ?? null)
                <section id="falha" class="mt-12 max-w-[68ch] scroll-mt-20" lang="{{ $reportLang }}">
                    <h2 class="mb-4 border-b border-ink-800 pb-2 text-xs font-semibold tracking-widest text-ink-700 uppercase">
                        Entenda a falha
                    </h2>

                    @foreach (['flaw' => 'A vulnerabilidade', 'process' => 'Como funciona', 'cause' => 'Por que é vulnerável', 'location' => 'Onde está', 'discovery' => 'Como foi descoberta'] as $part => $label)
                        @if ($vulnerability[$part])
                            <h3 class="{{ $loop->first ? '' : 'mt-5' }} mb-1.5 text-sm font-medium text-ink-200">{{ $label }}</h3>
                            <p class="text-[0.975rem] leading-7 text-ink-200">{{ $vulnerability[$part] }}</p>
                        @endif
                    @endforeach

                    @if ($vulnerability['poc'])
                        <div id="poc" class="mt-6 scroll-mt-20 rounded-lg border border-rose-900/50 bg-rose-950/15 px-4 py-3">
                            <h3 class="mb-1.5 text-sm font-medium text-ink-200">{{ $vulnerability['poc_private'] ? 'PoC existente, privada' : 'PoC pública' }}</h3>
                            @if ($vulnerability['poc_private'])
                                <p class="mb-1.5 text-xs text-ink-700">O exploit existe, mas quem o tem não o publicou.</p>
                            @endif
                            <p class="text-[0.975rem] leading-7 text-ink-200">{{ $vulnerability['poc'] }}</p>
                            @if ($vulnerability['poc_url'] && ! $vulnerability['poc_private'])
                                <p class="mt-2 text-xs text-ink-700">
                                    Publicada em:
                                    <a href="{{ $vulnerability['poc_url'] }}" target="_blank" rel="noopener noreferrer nofollow"
                                       class="font-mono break-all text-rose-300 hover:underline">{{ preg_replace('#^https?://#', '', $vulnerability['poc_url']) }} ↗</a>
                                </p>
                            @endif
                        </div>
                    @endif

                    <p class="mt-3 text-[11px] text-ink-700">
                        Explicação gerada por IA a partir da matéria e dos artigos{{ $vulnerability['at'] ? ', em '.\Carbon\Carbon::parse($vulnerability['at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '' }}.
                    </p>
                </section>
            @endif

            @if ($exploits)
                @php($exploitCves = collect($exploits)->pluck('cves')->flatten()->unique()->values())
                <section id="exploits" class="mt-12 max-w-[68ch] scroll-mt-20">
                    <h2 class="mb-2 border-b border-ink-800 pb-2 text-xs font-semibold tracking-widest text-ink-700 uppercase">
                        {{ count($exploits) === 1 ? 'Exploit público' : count($exploits).' exploits públicos' }}
                    </h2>
                    <p class="mb-4 text-xs leading-5 text-ink-700">
                        Há código de exploração publicado no Exploit-DB para
                        {{ $exploitCves->take(3)->implode(', ') }}{{ $exploitCves->count() > 3 ? ' e outros' : '' }}.
                        @unless ($exploitCredible)
                            A análise do arquivo indica que ele não explora a falha de forma crível.
                        @endunless
                        O código não é reproduzido aqui: o link leva à fonte, e a explicação foi escrita pela IA a partir do arquivo publicado.
                    </p>

                    @foreach (array_filter([array_slice($exploits, 0, 3), array_slice($exploits, 3)]) as $extra => $chunk)
                        <ol @if ($extra) id="exploits-extra" hidden @endif class="space-y-3 {{ $extra ? 'mt-3' : '' }}">
                            @foreach ($chunk as $exploit)
                                <li class="rounded-lg border border-rose-900/50 bg-rose-950/15 px-4 py-3">
                                    <div class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-ink-700">
                                        <a href="{{ $exploit['url'] }}" target="_blank" rel="noopener noreferrer nofollow"
                                           class="font-mono text-rose-300 hover:underline">exploit-db.com · {{ $exploit['edb_id'] }} ↗</a>
                                        @if ($exploit['type'])
                                            <span>·</span>
                                            <span>{{ $exploitTypes[$exploit['type']] ?? $exploit['type'] }}</span>
                                        @endif
                                        @if ($exploit['platform'])
                                            <span>·</span>
                                            <span class="font-mono">{{ $exploit['platform'] }}</span>
                                        @endif
                                        @if ($exploit['published_at'])
                                            <span>·</span>
                                            <span>pub. {{ \Carbon\Carbon::parse($exploit['published_at'])->format('d/m/Y') }}</span>
                                        @endif
                                        @if ($exploit['verified'])
                                            <span class="rounded bg-emerald-900/40 px-1.5 py-px text-emerald-300"
                                                  title="A equipe do Exploit-DB testou e confirmou que o exploit funciona.">verificado</span>
                                        @else
                                            <span class="rounded border border-ink-800 px-1.5 py-px"
                                                  title="A equipe do Exploit-DB ainda não testou este exploit.">não verificado</span>
                                        @endif
                                        @unless ($exploit['credible'])
                                            <span class="rounded border border-amber-800/60 px-1.5 py-px text-amber-300"
                                                  title="A explicação da IA indica que o código não explora a falha de forma crível.">duvidoso</span>
                                        @endunless
                                    </div>

                                    <h3 class="mb-1.5 text-sm leading-snug font-medium text-ink-200">{{ $exploit['title'] }}</h3>

                                    @if ($exploit['explanation'])
                                        <p class="text-[13px] leading-6 text-ink-400">{{ $exploit['explanation'] }}</p>
                                    @endif

                                    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-ink-700">
                                        @foreach ($exploit['cves'] as $cve)
                                            <a rel="nofollow" href="{{ $feedUrl('cves', $cve) }}" class="font-mono hover:text-accent">{{ $cve }}</a>
                                        @endforeach
                                        @if ($exploit['author'])
                                            <span>· autor: {{ $exploit['author'] }}</span>
                                        @endif
                                        @if ($exploit['explanation'])
                                            <span>· explicação gerada por IA</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endforeach

                    @if (count($exploits) > 3)
                        <button type="button"
                                data-toggle-target="#exploits-extra"
                                data-label-more="+{{ count($exploits) - 3 }} exploits"
                                data-label-less="ver menos"
                                class="mt-3 text-xs text-accent hover:underline">
                            +{{ count($exploits) - 3 }} exploits
                        </button>
                    @endif
                </section>
            @endif

            @foreach (['technical' => ['detalhes-tecnicos', 'Detalhes técnicos'], 'fix' => ['detalhes-da-correcao', 'Detalhes da correção'], 'remediation' => ['remediacao', 'Remediação'], 'conflicting' => ['relatos-divergentes', 'Relatos divergentes']] as $part => [$anchor, $label])
                @if ($report[$part])
                    <section id="{{ $anchor }}" class="mt-12 max-w-[68ch] scroll-mt-20">
                        <h2 class="mb-4 border-b border-ink-800 pb-2 text-xs font-semibold tracking-widest text-ink-700 uppercase">
                            {{ $label }}
                        </h2>
                        <div class="prose-brief" lang="{{ $reportLang }}">
                            {!! Markdown::toHtml($report[$part]) !!}
                        </div>
                    </section>
                @endif
            @endforeach

            @if ($case['timeline'])
                <h2 class="mt-12 mb-4 border-b border-ink-800 pb-2 text-xs font-semibold tracking-widest text-ink-700 uppercase">
                    Linha do tempo
                </h2>

                <ol class="space-y-6">
                    @foreach ($case['timeline'] as $entry)
                        <li class="border-l-2 border-ink-800 pl-4">
                            <div class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-ink-700">
                                <a href="{{ $entry['news_url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="font-mono text-accent hover:underline">{{ $entry['source_host'] }} ↗</a>
                                @if ($entry['published_at'])
                                    <span>·</span>
                                    <span>pub. {{ \Carbon\Carbon::parse($entry['published_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</span>
                                @endif
                                @if ($entry['collected_at'])
                                    <span>·</span>
                                    <span>coletado {{ \Carbon\Carbon::parse($entry['collected_at'])->timezone('America/Sao_Paulo')->format('d/m H:i') }}</span>
                                @endif
                                @if ($loop->last)
                                    <span class="rounded bg-accent/15 px-1.5 py-px text-accent">primeiro registro</span>
                                @endif
                                @if ($entry['summary_only'] ?? false)
                                    <span class="rounded bg-amber-900/40 px-1.5 py-px text-amber-200"
                                          title="A fonte não liberou o texto completo; esta análise saiu só do resumo do feed.">só resumo do feed</span>
                                @endif
                            </div>

                            <h3 class="mb-1.5 text-sm leading-snug font-medium text-ink-200">{{ $entry['title'] }}</h3>

                            @if ($entry['new_context'])
                                <p class="mb-2 rounded border border-accent/25 bg-accent/5 px-3 py-2 text-[13px] leading-6 text-accent">
                                    {{ $entry['new_context'] }}
                                </p>
                            @endif

                            <details class="group">
                                <summary class="cursor-pointer list-none text-xs text-ink-700 hover:text-ink-400">
                                    <span class="group-open:hidden">ler a análise desta fonte</span>
                                    <span class="hidden group-open:inline">ocultar</span>
                                </summary>
                                <div class="prose-brief mt-3 max-w-[68ch] text-[0.9rem]">
                                    {!! Markdown::toHtml($entry['summary_with_context'] ?: $entry['summary']) !!}
                                </div>
                            </details>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($case['no_update_sources'])
                <h2 class="mt-10 mb-3 text-xs font-semibold tracking-widest text-ink-700 uppercase">
                    Repetiram sem novidade
                </h2>
                <ul class="space-y-1.5 text-xs text-ink-700">
                    @foreach ($case['no_update_sources'] as $entry)
                        <li>
                            <a href="{{ $entry['news_url'] }}" target="_blank" rel="noopener noreferrer" class="hover:text-ink-400">
                                <span class="font-mono">{{ $entry['source_host'] }}</span> — {{ $entry['title'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>

        {{-- Indicadores --}}
        <aside class="lg:sticky lg:top-20 lg:self-start">
            <h2 class="mb-3 text-[11px] font-semibold tracking-widest text-ink-700 uppercase">Indicadores</h2>

            @forelse (array_slice($case['indicators'], 0, 4) as $group)
                <div class="mb-4">
                    <h3 class="mb-1.5 text-[10px] font-semibold tracking-widest text-ink-700 uppercase">{{ $group['label'] }}</h3>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($group['values'] as $value)
                            <a rel="nofollow" href="{{ $feedUrl($group['field'], $value) }}"
                               class="rounded border border-ink-800 bg-ink-900 px-1.5 py-0.5 font-mono text-[11px] break-all text-ink-400 hover:border-accent/50 hover:text-accent">
                                {{ $value }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-xs text-ink-700">Nenhum indicador extraído.</p>
            @endforelse

            @if (count($case['indicators']) > 4)
                <div id="indicators-extra" hidden>
                    @foreach (array_slice($case['indicators'], 4) as $group)
                        <div class="mb-4">
                            <h3 class="mb-1.5 text-[10px] font-semibold tracking-widest text-ink-700 uppercase">{{ $group['label'] }}</h3>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($group['values'] as $value)
                                    <a rel="nofollow" href="{{ $feedUrl($group['field'], $value) }}"
                                       class="rounded border border-ink-800 bg-ink-900 px-1.5 py-0.5 font-mono text-[11px] break-all text-ink-400 hover:border-accent/50 hover:text-accent">
                                        {{ $value }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button"
                        data-toggle-target="#indicators-extra"
                        data-label-more="+{{ count($case['indicators']) - 4 }} grupos"
                        data-label-less="ver menos"
                        class="text-xs text-accent hover:underline">
                    +{{ count($case['indicators']) - 4 }} grupos
                </button>
            @endif
        </aside>
    </div>
@endsection
