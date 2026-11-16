@extends('layouts.app')

@section('title', 'Radar de domínios · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Models\LookalikeCapture;
    use App\Models\LookalikeDomain;
    use App\Models\LookalikeWatch;
    use App\Services\Lookalike\LookalikeRadar;
    use App\Services\Lookalike\VariantGenerator;

    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m \à\s H:i');
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-3xl">
                @include('alerts.partials.crumbs', ['here' => 'Radar de domínios'])
                <h1 class="text-xl font-semibold text-white">Radar de domínios</h1>
                <p class="text-sm text-ink-400">
                    Nomes parecidos com o seu domínio que alguém registrou: erro de digitação, letra trocada, “-login”, “-pix”, outra terminação.
                    Abrimos cada um no navegador, no Android e no iPhone, comparamos com o seu site e a IA dá o parecer antes de avisar.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($watches->count() > 1)
                    <nav aria-label="Radar" class="flex flex-wrap gap-1 rounded-lg border border-ink-800 p-1">
                        @foreach ($watches as $w)
                            <a href="{{ route('alerts.lookalikes', ['radar' => $w->id]) }}" @if ($watch?->id === $w->id) aria-current="page" @endif
                               class="inline-flex min-h-11 items-center rounded-md px-3 font-mono text-sm {{ $watch?->id === $w->id ? 'bg-ink-800 text-white' : 'text-ink-400 hover:text-ink-200' }}">{{ $w->domain->domain }}</a>
                        @endforeach
                    </nav>
                @endif
                @if ($watches->isNotEmpty() && $canCreate)
                    <a href="{{ route('alerts.lookalikes.interview', ['passo' => 1]) }}" class="{{ $button }} border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white">Adicionar domínio</a>
                @endif
            </div>
        </div>

        @if (! $watch)
            <section class="max-w-2xl space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-6">
                <h2 class="font-semibold text-white">Integre o Radar para começar</h2>
                <p class="text-sm text-ink-200">
                    Uma entrevista de 3 passos, separada da de notícias: o domínio, a marca e a prova de que o domínio é seu.
                    Um domínio já comprovado em Notícias é reaproveitado com um clique.
                </p>
                @if ($canCreate)
                    <a href="{{ route('alerts.lookalikes.interview', ['passo' => 1]) }}" class="{{ $button }} bg-accent font-semibold text-ink-950 hover:brightness-110">Integrar o Radar</a>
                @else
                    <p class="text-sm text-ink-400">Nenhum Radar dos seus setores por enquanto. Quem configura o Radar é quem administra a empresa ou quem responde por um setor. Peça a uma dessas pessoas.</p>
                @endif
            </section>
        @elseif (! $watch->isCompleted())
            <section class="max-w-2xl space-y-3 rounded-xl border border-amber-800 bg-amber-950/30 p-6">
                <h2 class="font-semibold text-white">A entrevista de <span class="font-mono">{{ $domain->domain }}</span> não terminou</h2>
                <p class="text-sm text-amber-100">O Radar só começa depois do passo 3, com a ativação.</p>
                @if ($canManage)
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('alerts.lookalikes.interview', ['passo' => $watch->step_reached, 'radar' => $watch->id]) }}"
                       class="{{ $button }} bg-amber-300 font-semibold text-amber-950 hover:brightness-110">Continuar a entrevista (passo {{ $watch->step_reached }})</a>
                    <form method="POST" action="{{ route('alerts.lookalikes.destroy', ['radar' => $watch->id]) }}"
                          data-confirm="Excluir o rascunho do Radar de {{ $domain->domain }}? As respostas da entrevista serão apagadas.">
                        @csrf
                        @method('DELETE')
                        <button class="{{ $button }} text-red-300 hover:text-red-200">Excluir rascunho</button>
                    </form>
                </div>
                @endif
            </section>
        @else
            @php
                $stats = $watch->stats ?? [];
                $running = in_array($watch->scan_status, ['pending', 'running'], true);
                $pill = match (true) {
                    ! $watch->isActive() => ['pausado', 'border-ink-700 bg-ink-850 text-ink-400', 'bg-ink-400'],
                    $running => [$watch->scan_status === 'running' ? 'varrendo agora' : 'varredura na fila', 'border-accent/40 bg-accent/10 text-amber-200', 'bg-accent animate-pulse'],
                    $watch->scan_status === 'failed' => ['última varredura falhou', 'border-red-900 bg-red-950/50 text-red-300', 'bg-red-400'],
                    default => ['ativo', 'border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
                };
                $threats = $counts['critical'] + $counts['high'];
                $pages = $watch->referencePages();
            @endphp

            {{-- Situação do Radar --}}
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-ink-800 bg-ink-900 px-5 py-4">
                <div class="min-w-0 flex-[1_1_420px] space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-[15px] text-white">{{ $domain->domain }}</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $pill[1] }}">
                            <span class="size-1.5 rounded-full {{ $pill[2] }}"></span>{{ $pill[0] }}
                        </span>
                    </div>
                    <p class="text-sm text-ink-400">
                        @if ($watch->last_scan_at)
                            Última varredura {{ $when($watch->last_scan_at) }}:
                            {{ number_format($stats['checked'] ?? 0, 0, ',', '.') }} nomes conferidos, {{ $counts['all'] }} existem.
                            @if ($watch->isActive() && $watch->next_scan_at)
                                Próxima em {{ $watch->next_scan_at->timezone('America/Sao_Paulo')->format('d/m \à\s H:i') }}. O Radar varre todo dia à 01:00: os nomes são conferidos de novo, o DNS dos achados é relido e as páginas em branco são abertas de novo.
                            @endif
                        @else
                            O domínio entrou no Radar. A primeira varredura confere centenas de nomes no DNS e leva uns minutos.
                        @endif
                    </p>
                    @if ($counts['queued'])
                        <p class="text-sm text-amber-200">{{ $counts['queued'] }} {{ $counts['queued'] === 1 ? 'página esperando' : 'páginas esperando' }} a visita do navegador.</p>
                    @endif
                    @if (! empty($stats['certificates_error']))
                        <p class="text-xs text-ink-400">Busca em certificados: {{ $stats['certificates_error'] }}. As variações do nome foram conferidas normalmente.</p>
                    @endif
                </div>
                @if ($canManage)
                <div class="flex flex-wrap items-center gap-2">
                    @if ($watch->isActive())
                        <form method="POST" action="{{ route('alerts.lookalikes.scan', $watch->urlParams()) }}">
                            @csrf
                            <button @disabled($running || $watch->manualScanAvailableAt())
                                    title="{{ $watch->manualScanAvailableAt() ? 'Liberado de novo em '.$when($watch->manualScanAvailableAt()) : 'Confere tudo de novo agora (1 vez por dia)' }}"
                                    class="{{ $button }} border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white disabled:cursor-not-allowed disabled:opacity-50">Verificar agora</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('alerts.lookalikes.pause', $watch->urlParams()) }}">
                        @csrf
                        <button class="{{ $button }} text-ink-400 hover:text-white">{{ $watch->isActive() ? 'Pausar' : 'Retomar' }}</button>
                    </form>
                    <a href="{{ route('alerts.lookalikes.interview', ['passo' => 2, 'radar' => $watch->id]) }}" class="{{ $button }} text-ink-200 hover:text-white">Editar entrevista</a>
                    <form method="POST" action="{{ route('alerts.lookalikes.destroy', $watch->urlParams()) }}"
                          data-confirm="Excluir o Radar de {{ $domain->domain }}? Os domínios achados, os prints e as denúncias marcadas serão apagados.">
                        @csrf
                        @method('DELETE')
                        <button class="{{ $button }} text-red-300 hover:text-red-200">Excluir</button>
                    </form>
                </div>
                @endif
                @if ($watch->last_log)
                    <details class="w-full border-t border-ink-850 pt-3 text-sm">
                        <summary class="cursor-pointer text-ink-400 hover:text-ink-200">Como foi a última varredura</summary>
                        <ul class="mt-2 space-y-0.5 text-ink-200">
                            @foreach ($watch->last_log as $line)
                                <li>· {{ $line['text'] }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>

            {{-- Resumo por nível (clicável) --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ([
                    ['critical', 'ameacas', 'Críticos', 'Copiam o site ou pedem senha, cartão ou Pix', 'border-red-900/80 bg-red-950/30', 'text-red-300'],
                    ['high', 'ameacas', 'Altos', 'Site no ar que usa a marca ou parece preparar golpe', 'border-orange-900/80 bg-orange-950/25', 'text-orange-300'],
                    ['watch', 'vigiar', 'Vigiar', 'Sem página ainda, ou desviando para outro site quem erra o nome', 'border-yellow-900/70 bg-yellow-950/20', 'text-yellow-200'],
                    ['low', 'baixo', 'Baixo', 'Só parecidos: outro negócio, antigos ou de vocês', 'border-ink-800 bg-ink-900', 'text-ink-200'],
                ] as [$risk, $target, $label, $hint, $box, $tone])
                    <a href="{{ route('alerts.lookalikes', $watch->urlParams(['filtro' => $target])) }}"
                       class="rounded-xl border p-4 transition hover:brightness-125 {{ $box }}">
                        <p class="text-[11px] tracking-widest text-ink-400 uppercase">{{ $label }}</p>
                        <p class="mt-1 font-mono text-3xl font-semibold {{ $counts[$risk] ? $tone : 'text-ink-700' }}">{{ $counts[$risk] }}</p>
                        <p class="mt-1 text-xs text-ink-400">{{ $hint }}</p>
                    </a>
                @endforeach
            </div>

            <section class="overflow-hidden rounded-xl border border-ink-800 bg-ink-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-800 px-5 py-3">
                    <h2 class="font-semibold text-white">{{ [
                        'ameacas' => 'Ameaças: críticos e altos',
                        'vigiar' => 'Para vigiar',
                        'baixo' => 'Risco baixo',
                        'marcados' => 'Marcados por você',
                    ][$filter] ?? 'Todos os domínios achados' }}</h2>
                    <nav aria-label="Filtro" class="flex flex-wrap gap-1 rounded-lg border border-ink-800 p-1">
                        @foreach (LookalikeRadar::FILTERS as $value => $label)
                            @php
                                $count = match ($value) {
                                    'ameacas' => $threats,
                                    'vigiar' => $counts['watch'],
                                    'baixo' => $counts['low'],
                                    'marcados' => $counts['marked'],
                                    default => $counts['all'],
                                };
                            @endphp
                            <a href="{{ route('alerts.lookalikes', $watch->urlParams(['filtro' => $value])) }}"
                               @if ($filter === $value) aria-current="page" @endif
                               class="inline-flex min-h-9 items-center gap-1.5 rounded-md px-3 text-sm {{ $filter === $value ? 'bg-ink-800 text-white' : 'text-ink-400 hover:text-ink-200' }}">
                                @if ($value === 'ameacas')<span class="size-2 rounded-full bg-red-400" aria-hidden="true"></span>@endif
                                {{ $label }}
                                <span class="rounded px-1.5 font-mono text-[11px] {{ $value === 'ameacas' && $count ? 'bg-red-950/70 text-red-200' : 'bg-ink-850 text-ink-400' }}">{{ $count }}</span>
                            </a>
                        @endforeach
                    </nav>
                </div>

                @forelse ($lookalikes as $lookalike)
                    @php
                        $capture = $lookalike->lastCapture?->status === 'done' ? $lookalike->lastCapture : null;
                        // Data do registro e e-mail já estão na linha de cima.
                        $reasons = collect($lookalike->signals ?? [])->where('points', '>', 0)->whereNotIn('key', ['recent', 'mx'])->sortByDesc('points')->take(3)->pluck('label');
                        $hosting = $lookalike->hosting['org'] ?? $lookalike->hosting['network'] ?? null;
                        $threat = in_array($lookalike->risk, ['critical', 'high'], true) || $lookalike->verdict === 'threat';
                    @endphp
                    <div class="grid grid-cols-[88px_1fr] gap-x-3 gap-y-3 border-t border-ink-850 px-4 py-4 first:border-t-0 sm:grid-cols-[176px_1fr] sm:gap-x-4 sm:px-5 lg:grid-cols-[176px_1fr_auto] {{ $lookalike->risk === 'critical' && ! $lookalike->isDismissed() ? 'shadow-[inset_3px_0_0_var(--color-red-500)]' : '' }} {{ $lookalike->heldByAi() ? 'bg-violet-950/20' : '' }}">
                        <a href="{{ route('alerts.lookalikes.show', $lookalike) }}" class="block" tabindex="-1">
                            @include('alerts.lookalikes.partials.thumb', ['lookalike' => $lookalike, 'capture' => $capture])
                        </a>

                        <div class="min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('alerts.lookalikes.show', $lookalike) }}" class="font-mono text-[15px] font-semibold break-all text-white hover:text-accent">{{ $lookalike->displayHost() }}</a>
                                @include('alerts.lookalikes.partials.risk', ['risk' => $lookalike->risk])
                                @include('alerts.lookalikes.partials.ai-badge', ['lookalike' => $lookalike])
                                @if ($lookalike->heldByAi())
                                    <span class="rounded border border-violet-700/70 bg-violet-950/60 px-1.5 py-px text-xs text-violet-200">Segurado pela IA</span>
                                @endif
                                @if ($lookalike->verdict)
                                    <span class="rounded border border-ink-700 px-1.5 py-px text-xs text-ink-200">{{ LookalikeDomain::VERDICT_LABELS[$lookalike->verdict] }}</span>
                                @endif
                                @if ($lookalike->takenDown())
                                    <span class="rounded border border-emerald-800/70 bg-emerald-950/50 px-1.5 py-px text-xs text-emerald-300">Fora do ar após denúncia</span>
                                @elseif ($lookalike->reported())
                                    <span class="rounded border border-sky-800/70 bg-sky-950/50 px-1.5 py-px text-xs text-sky-300">Denunciado</span>
                                @endif
                            </div>
                            @if ($lookalike->isIdn())
                                <p class="font-mono text-xs text-ink-400">{{ $lookalike->host }} · {{ $lookalike->idnNote() }}</p>
                            @endif
                            <p class="text-sm text-ink-200">
                                {{ ucfirst($lookalike->stateLabel()) }}
                                @if ($lookalike->registeredAgo())
                                    · registrado {{ $lookalike->registeredAgo() }}
                                @endif
                                @if ($lookalike->hasMx())
                                    · <span class="text-amber-200">recebe e-mail</span>
                                @endif
                                @if ($hosting)
                                    · {{ $hosting }}
                                @endif
                            </p>
                            @if ($lookalike->ai_reason)
                                <p class="text-sm {{ $lookalike->heldByAi() ? 'text-violet-200' : 'text-ink-200' }}">IA: {{ $lookalike->ai_reason }}</p>
                            @elseif ($reasons->isNotEmpty())
                                <ul class="flex flex-wrap gap-x-3 gap-y-0.5 text-sm text-ink-400">
                                    @foreach ($reasons as $reason)
                                        <li>· {{ $reason }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            <p class="flex flex-wrap gap-1 pt-0.5">
                                @foreach ($lookalike->techniqueLabels() as $technique)
                                    <span class="rounded border border-ink-800 bg-ink-950 px-1.5 py-px text-[11px] text-ink-400">{{ $technique }}</span>
                                @endforeach
                            </p>
                        </div>

                        <div class="col-span-2 flex flex-wrap items-start gap-1.5 lg:col-span-1 lg:flex-col lg:items-end">
                            <div class="flex flex-wrap gap-1.5">
                                {{-- A IA julgou que não é golpe (e o cliente não disse o contrário): sem atalho para denunciar. --}}
                                @if ($threat && ! $lookalike->isDismissed() && ($lookalike->verdict === 'threat' || ! $lookalike->heldByAi()))
                                    <a href="{{ route('alerts.lookalikes.kit', $lookalike) }}"
                                       class="inline-flex min-h-9 items-center rounded-md border border-red-900/80 px-3 text-sm text-red-200 hover:border-red-500">Denunciar</a>
                                @endif
                                <a href="{{ route('alerts.lookalikes.show', $lookalike) }}"
                                   class="inline-flex min-h-9 items-center gap-1 rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-accent hover:text-white">
                                    Detalhes
                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                                </a>
                            </div>
                            <div class="flex gap-1.5">
                                @if ($lookalike->verdict)
                                    <form method="POST" action="{{ route('alerts.lookalikes.verdict', $lookalike) }}">
                                        @csrf
                                        <button name="acao" value="desfazer" class="min-h-9 rounded-md px-2 text-sm text-ink-400 underline-offset-2 hover:text-white hover:underline">Desfazer</button>
                                    </form>
                                @else
                                    @foreach (['nosso' => 'É nosso', 'seguro' => 'Não é ameaça'] as $acao => $label)
                                        <form method="POST" action="{{ route('alerts.lookalikes.verdict', $lookalike) }}">
                                            @csrf
                                            <button name="acao" value="{{ $acao }}" class="min-h-9 rounded-md border border-ink-800 px-2.5 text-sm text-ink-400 hover:border-ink-400 hover:text-ink-200">{{ $label }}</button>
                                        </form>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-ink-400">
                        @if ($running && ! $watch->last_scan_at)
                            A primeira varredura está rodando. Os domínios achados aparecem aqui.
                        @elseif ($filter === 'ameacas')
                            Nenhuma ameaça aberta. Os registrados sem página ficam em “Vigiar”; se um deles montar um site imitando o seu, ele sobe para cá e você recebe o aviso.
                        @elseif ($filter === 'marcados')
                            Nada marcado ainda. Use “É nosso” nos registros defensivos da empresa e “Não é ameaça” no que for só parecido: a IA aprende com isso.
                        @else
                            Nenhum domínio {{ $filter === 'todos' ? 'parecido existe no DNS por enquanto' : 'neste filtro' }}.
                        @endif
                    </p>
                @endforelse

                @if ($lookalikes->hasPages())
                    <div class="border-t border-ink-850 px-5 py-3 text-sm">{{ $lookalikes->links() }}</div>
                @endif
            </section>

            {{-- Referência: 3 páginas do site do cliente nos 3 aparelhos --}}
            <details class="rounded-xl border border-ink-800 bg-ink-900 px-5 py-4" @if ($pages === []) open @endif>
                <summary class="cursor-pointer font-semibold text-white">
                    Seu site, para comparação
                    <span class="font-normal text-ink-400">· {{ $pages ? count($pages).(count($pages) === 1 ? ' página' : ' páginas').' nos 3 aparelhos, de '.$watch->reference_at?->timezone('America/Sao_Paulo')->format('d/m') : 'ainda sem prints' }}</span>
                </summary>
                <div class="mt-3 space-y-4">
                    @if ($watch->reference_status === 'no_consent')
                        <p class="rounded-lg border border-amber-800 bg-amber-950/30 px-4 py-3 text-sm text-amber-100">
                            Para guardarmos 3 páginas do seu site como referência (abertas 1 vez por mês por um navegador, como computador, Android e iPhone), autorize os prints
                            no @if ($canManage)<a href="{{ route('alerts.lookalikes.interview', ['passo' => 3, 'radar' => $watch->id]) }}" class="text-accent underline">passo 3 da entrevista do Radar</a>@else passo 3 da entrevista do Radar (quem configura o Radar faz isso)@endif.
                            Sem a referência, o Radar funciona, mas não compara a aparência dos achados com a do seu site.
                        </p>
                    @elseif ($watch->reference_status === 'pending')
                        <p class="text-sm text-amber-200">Os prints do seu site estão na fila do navegador.</p>
                    @elseif ($watch->reference_status === 'failed')
                        <p class="text-sm text-red-300">Não conseguimos abrir o seu site no navegador{{ ! empty($watch->reference['error']) ? ': '.$watch->reference['error'] : '' }}. Se ele usa proteção anti-robô, libere o User-Agent SecurityMonitorBot no firewall.</p>
                    @elseif ($pages === [])
                        <p class="text-sm text-ink-400">Os prints do seu site entram na próxima varredura.</p>
                    @endif

                    @foreach ($pages as $index => $page)
                        <div class="space-y-2">
                            <p class="text-sm"><span class="text-ink-200">{{ $page['title'] ?: 'Página '.($index + 1) }}</span> · <span class="font-mono text-xs break-all text-ink-400">{{ $page['url'] }}</span></p>
                            <div class="grid grid-cols-[minmax(0,2.4fr)_minmax(0,1fr)_minmax(0,1fr)] items-start gap-2">
                                @foreach (LookalikeCapture::DEVICES as $device => $label)
                                    @if (! empty($page['shots'][$device]))
                                        <a href="{{ route('alerts.lookalikes.reference', [$watch, $index + 1, $device]) }}" target="_blank" rel="noopener" class="space-y-1">
                                            <img src="{{ route('alerts.lookalikes.reference', [$watch, $index + 1, $device]) }}" alt="{{ $label }}" loading="lazy" class="max-h-56 w-full rounded-md border border-ink-800 bg-white object-cover object-top">
                                            <span class="block text-[11px] text-ink-400">{{ $label }}</span>
                                        </a>
                                    @else
                                        <span></span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>

            @if ($watch->free_variants)
                <details class="rounded-xl border border-ink-800 bg-ink-900 px-5 py-4">
                    <summary class="cursor-pointer font-semibold text-white">
                        Variações perigosas ainda livres <span class="font-normal text-ink-400">· registre antes que alguém registre</span>
                    </summary>
                    <p class="mt-2 text-sm text-ink-400">Ninguém registrou estes nomes até a última varredura. São os que mais enganam; registrar os principais e redirecionar para o seu site fecha a porta.</p>
                    <ul class="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($watch->free_variants as $free)
                            <li class="flex items-baseline justify-between gap-2 border-b border-ink-850 py-1 text-sm">
                                <span class="font-mono break-all text-ink-200">{{ str_contains($free['host'], 'xn--') && function_exists('idn_to_utf8') ? idn_to_utf8($free['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : $free['host'] }}</span>
                                <span class="shrink-0 text-xs text-ink-400">{{ VariantGenerator::LABELS[$free['technique']] ?? '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif
    </div>
@endsection
