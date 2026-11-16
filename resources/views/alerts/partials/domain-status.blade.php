@php
    $statusLabels = [
        'pending' => 'na fila',
        'running' => 'rodando',
        'done' => 'concluída',
        'partial' => 'parcial',
        'failed' => 'falhou',
        'blocked' => 'bloqueada',
    ];
@endphp

@php
    // Origem de cada tecnologia no perfil: o que veio da entrevista não se remove por aqui.
    $sources = $profileTechSources ?? [];
    $findings = $domain->findings->sortBy(fn ($f) => [$f->status === 'ignored', -$f->confidence]);
    $kept = $findings->where('status', '!=', 'ignored')->count();
    $cloudflare = $domain->usesCloudflare();
@endphp

<section id="descoberta" class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-[15px] font-semibold text-white">
                @if ($findings->isEmpty())
                    Descoberta em {{ $domain->domain }}
                @else
                    Identificamos {{ $findings->count() }} {{ $findings->count() === 1 ? 'tecnologia' : 'tecnologias' }} em {{ $domain->domain }}
                    e {{ $kept === 1 ? 'ela está' : 'elas estão' }} no seu perfil
                @endif
            </h3>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-800 bg-emerald-950/60 px-2.5 py-0.5 text-xs text-emerald-300">
                <span class="size-1.5 rounded-full bg-emerald-400"></span>
                verificado {{ $domain->verified_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}
                · descoberta {{ $statusLabels[$domain->discovery_status] ?? $domain->discovery_status }}
            </span>
        </div>

        @if ($domain->last_scan_at)
            <p class="text-xs text-ink-400">
                Última leitura do site em {{ $domain->last_scan_at->timezone('America/Sao_Paulo')->format('d/m') }}
                @if ($domain->nextScanAt()) · próxima em {{ $domain->nextScanAt()->timezone('America/Sao_Paulo')->format('d/m') }} @endif
                · 1 vez por mês, no máximo {{ config('secmon.alerts.discovery.max_requests', 5) }} requisições ao site.
            </p>
        @endif

        @if ($domain->discovery_status === 'blocked')
            <p class="rounded-lg border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                Não conseguimos analisar o domínio: o site redireciona para um endereço interno. Por segurança, paramos ali.
            </p>
        @elseif ($domain->discovery_status === 'failed')
            <p class="rounded-lg border border-red-900 bg-red-950/40 px-4 py-3 text-sm text-red-200">
                A descoberta falhou: {{ $domain->last_error }}. Tentamos de novo na próxima execução.
            </p>
        @elseif (in_array($domain->discovery_status, ['pending', 'running'], true))
            <p class="text-sm text-ink-400">A descoberta está na fila. O que ela reconhecer entra direto no seu perfil e aparece aqui.</p>
        @elseif ($findings->isNotEmpty())
            <p class="text-sm text-ink-400">Já valem para os alertas. Se algo não for de vocês, remova: as próximas descobertas não colocam de volta.</p>
        @endif

        @if ($findings->isNotEmpty())
            <div class="overflow-hidden rounded-lg border border-ink-800">
                @foreach ($findings as $finding)
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-800 px-4 py-3 first:border-t-0">
                        <div class="min-w-0 flex-[1_1_180px]">
                            <p class="font-semibold text-white">{{ $finding->label }}</p>
                            <p class="text-xs text-ink-400">{{ $finding->category }}</p>
                        </div>
                        <code class="min-w-0 flex-[2_1_240px] font-mono text-xs break-all text-ink-200">{{ $finding->evidence }}</code>
                        <span class="shrink-0 rounded-full border px-2 py-0.5 text-xs {{ $finding->confidence >= 80 ? 'border-emerald-800 text-emerald-300' : 'border-sky-800 text-sky-300' }}">
                            confiança {{ $finding->confidenceLabel() }}
                        </span>
                        <div class="flex shrink-0 basis-56 items-center justify-end gap-2">
                            @if (! $finding->tech_id)
                                <span class="text-sm text-ink-400">só informativo</span>
                            @elseif ($finding->status === 'ignored')
                                <span class="text-sm text-ink-400">removido</span>
                                <form method="POST" action="{{ route('alerts.finding.decide', [$finding, 'aceitar']) }}">
                                    @csrf
                                    <button class="min-h-9 rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-ink-400">Adicionar de volta</button>
                                </form>
                            @elseif (($sources[$finding->tech_id] ?? null) === 'discovery')
                                <span class="text-sm text-emerald-300">no perfil</span>
                                <form method="POST" action="{{ route('alerts.finding.decide', [$finding, 'ignorar']) }}">
                                    @csrf
                                    <button class="min-h-9 rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-red-900 hover:text-red-300">Remover</button>
                                </form>
                            @else
                                <span class="text-sm text-emerald-300">já estava no perfil</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @foreach ($domain->walls() as $wall)
            <div class="space-y-3 rounded-lg border border-amber-800 bg-amber-950/30 px-4 py-3 text-sm text-amber-100">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 size-5 shrink-0 text-amber-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <p>
                        <b class="text-white">Não conseguimos ler tudo:</b>
                        @isset($wall['url'])
                            <span class="font-mono break-all">{{ $wall['url'] }}</span> foi barrada
                            {{ ($wall['por'] ?? null) === 'cloudflare' ? 'pelo desafio anti-robô da Cloudflare' : 'pela proteção anti-robô do site' }}.
                            {{ $cloudflare ? 'O passo a passo abaixo libera o Security Monitor.' : 'Libere o User-Agent com "SecurityMonitorBot" numa regra do firewall do site e rode a descoberta de novo.' }}
                        @else
                            {{ ucfirst($wall['texto']) }}
                        @endisset
                    </p>
                </div>
                @unless ($cloudflare)
                    @include('alerts.partials.rerun')
                @endunless
            </div>
        @endforeach

        @if ($cloudflare)
            @include('alerts.partials.cloudflare-release')
        @endif

        @foreach (collect($domain->hints ?? [])->reject(fn ($hint) => is_array($hint)) as $hint)
            <div class="flex items-start gap-3 rounded-lg border border-accent/40 bg-accent/5 px-4 py-3 text-sm text-ink-200">
                <svg class="mt-0.5 size-5 shrink-0 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l9 16H3z"/><path d="M12 10v4"/><path d="M12 17h.01"/></svg>
                <p><b class="text-white">De brinde:</b> {{ $hint }}</p>
            </div>
        @endforeach
    </section>
