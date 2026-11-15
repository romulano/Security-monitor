@extends('layouts.app')

@section('title', $lookalike->displayHost().' · Radar de domínios · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Models\LookalikeCapture;
    use App\Models\LookalikeDomain;
    use App\Services\Lookalike\AlertPhoto;

    $capture = $lookalike->lastCapture?->status === 'done' ? $lookalike->lastCapture : null;
    $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i');
    $rdap = $lookalike->rdap ?? [];
    $hosting = $lookalike->hosting ?? [];
    $urgent = in_array($lookalike->risk, ['critical', 'high'], true) || $lookalike->verdict === 'threat';
    $alert = $lookalike->latestAlert;
    $aiCut = (int) config('secmon.lookalikes.ai_threshold', 70);
    $similar = $capture ? AlertPhoto::mostSimilar($capture) : null;
    $refPage = $similar ? ($lookalike->watch->referencePages()[$similar['page']] ?? null) : null;
    $explain = [
        'pending' => 'O navegador ainda não visitou este domínio. Os prints aparecem aqui depois da visita.',
        'no_site' => 'O domínio existe, mas não aponta para nenhum site. Relemos o DNS todo dia: se ele ganhar um endereço, o navegador vai lá.',
        'offline' => 'O domínio aponta para um servidor, mas a página não respondeu. Tentamos de novo nas próximas verificações.',
        'parked' => 'Página de estacionamento, de venda do domínio ou a página padrão do servidor. Ninguém montou um site ainda.',
        'blank' => 'A página abre, mas está vazia. Pode ser um site em montagem ou um golpe que só aparece para certos visitantes. O navegador abre de novo todo dia.',
        'challenge' => 'O site mostra um desafio anti-robô (como o “Um momento…” da Cloudflare) em vez do conteúdo. Golpes usam isso para se esconder de quem vigia. O navegador tenta de novo todo dia.',
        'redirects_to_you' => 'O domínio manda o visitante para o seu site. Costuma ser um registro defensivo da própria empresa.',
        'gone' => 'O domínio não existe mais no DNS (expirou ou foi suspenso).',
    ][$lookalike->state] ?? null;
    $reports = collect($lookalike->reports ?? [])->filter(fn ($r) => ! empty($r['at']));
@endphp

@section('content')
    <div class="space-y-5">
        <a href="{{ route('alerts.lookalikes', $watch->urlParams()) }}" class="inline-flex items-center gap-1 text-sm text-ink-400 hover:text-white">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            Radar de domínios
        </a>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1.5">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h1 class="font-mono text-2xl font-semibold break-all text-white">{{ $lookalike->displayHost() }}</h1>
                    @include('alerts.lookalikes.partials.risk', ['risk' => $lookalike->risk])
                    @include('alerts.lookalikes.partials.ai-badge', ['lookalike' => $lookalike])
                    @if ($lookalike->verdict)
                        <span class="rounded border border-ink-700 px-1.5 py-px text-xs text-ink-200">{{ LookalikeDomain::VERDICT_LABELS[$lookalike->verdict] }}</span>
                    @endif
                    @if ($lookalike->takenDown())
                        <span class="rounded border border-emerald-800/70 bg-emerald-950/50 px-1.5 py-px text-xs text-emerald-300">Fora do ar após denúncia</span>
                    @elseif ($reports->isNotEmpty())
                        <span class="rounded border border-sky-800/70 bg-sky-950/50 px-1.5 py-px text-xs text-sky-300">Denunciado</span>
                    @endif
                </div>
                @if ($lookalike->isIdn())
                    <p class="font-mono text-sm text-ink-400">{{ $lookalike->host }} · na barra do navegador aparece “{{ $lookalike->displayHost() }}”; {{ $lookalike->idnNote() }}</p>
                @endif
                <p class="text-sm text-ink-400">
                    Parecido com <span class="font-mono text-ink-200">{{ $lookalike->watch->domain->domain }}</span>
                    · {{ implode(' · ', $lookalike->techniqueLabels()) ?: 'achado em certificado' }}
                    · visto pela primeira vez em {{ $lookalike->first_seen_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}
                    @if ($lookalike->risk_changed_at && $lookalike->previous_risk)
                        · <span class="text-amber-200">era {{ LookalikeDomain::RISK_LABELS[$lookalike->previous_risk] ?? $lookalike->previous_risk }} até {{ $lookalike->risk_changed_at->timezone('America/Sao_Paulo')->format('d/m') }}</span>
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('alerts.lookalikes.kit', $lookalike) }}"
                   class="inline-flex min-h-10 items-center gap-1.5 rounded-lg px-4 text-sm font-semibold {{ $urgent ? 'bg-red-400 text-red-950 hover:brightness-110' : 'border border-ink-700 text-ink-200 hover:border-ink-400' }}">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 22V4a1 1 0 0 1 1-1h12l-2 4 2 4H5"/></svg>
                    Kit de denúncia
                </a>
                <button type="button" popovertarget="avaliar" class="inline-flex min-h-10 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400">
                    {{ $lookalike->verdict ? 'Avaliação: '.LookalikeDomain::VERDICT_LABELS[$lookalike->verdict] : 'Avaliar' }}
                </button>
            </div>

            {{-- Avaliação com motivo opcional (Popover API; o app.js posiciona junto ao botão). --}}
            <form id="avaliar" popover data-float method="POST" action="{{ route('alerts.lookalikes.verdict', $lookalike) }}" aria-label="Avaliar domínio"
                  class="w-[min(24rem,calc(100vw-2rem))] rounded-xl border border-ink-800 bg-ink-900 p-4 text-left text-ink-200 shadow-2xl shadow-black/60">
                @csrf
                @include('alerts.lookalikes.partials.verdict-fields', ['lookalike' => $lookalike, 'id' => 'motivo-radar'])
                @if ($lookalike->verdict_note_flagged)
                    <p class="mt-2 text-xs text-ink-400">O motivo atual não vai para a IA: parece uma instrução para ela.</p>
                @endif
                <div class="mt-4 flex justify-between gap-2">
                    @if ($lookalike->verdict)
                        <button name="acao" value="desfazer" formnovalidate class="min-h-10 rounded-lg px-3 text-sm text-ink-400 hover:text-white">Desfazer</button>
                    @else
                        <span></span>
                    @endif
                    <div class="flex gap-2">
                        <button type="button" popovertarget="avaliar" popovertargetaction="hide" class="min-h-10 rounded-lg border border-ink-800 px-3 text-sm text-ink-200 hover:border-ink-700">Cancelar</button>
                        <button class="min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar</button>
                    </div>
                </div>
            </form>
        </div>

        {{-- Parecer da IA (antes do aviso) --}}
        @if ($lookalike->ai_score !== null || $alert)
            @php $held = $alert?->status === 'held'; @endphp
            <section class="rounded-xl border p-5 {{ $held ? 'border-violet-800/70 bg-violet-950/25' : 'border-ink-800 bg-ink-900' }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-semibold {{ $held ? 'text-violet-200' : 'text-white' }}">{{ $held ? 'Segurado pela IA' : 'Parecer da IA' }}</h2>
                    @if ($alert)
                        <p class="text-xs text-ink-400">Aviso {{ $alert->kind === 'raised' ? 'de subida de nível' : 'de domínio novo' }} · {{ $alert->statusLabel() }}{{ $alert->notified_at ? ' às '.$alert->notified_at->timezone('America/Sao_Paulo')->format('d/m H:i') : '' }}</p>
                    @endif
                </div>
                @if ($lookalike->ai_score !== null)
                    <div class="mt-3 max-w-xl">
                        <div class="flex justify-between text-xs text-ink-400">
                            <span>Certeza da IA de que é golpe contra a sua marca</span>
                            <span class="font-mono font-semibold {{ $held ? 'text-violet-200' : 'text-emerald-300' }}">{{ $lookalike->ai_score }}%</span>
                        </div>
                        <div class="relative mt-1.5 h-1.5 rounded-full bg-ink-800">
                            <div class="h-full rounded-full {{ $held ? 'bg-violet-400' : 'bg-emerald-400' }}" style="width: {{ max(2, $lookalike->ai_score) }}%"></div>
                            <div class="absolute -top-1 h-3.5 w-0.5 rounded bg-ink-200" style="left: {{ $aiCut }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-ink-400">O aviso sai acima de {{ $aiCut }}% (a marca na barra).{{ $lookalike->ai_device && $lookalike->ai_device !== 'desktop' ? ' O golpe aparece mais no '.LookalikeCapture::DEVICES[$lookalike->ai_device].'.' : '' }}</p>
                    </div>
                @endif
                <p class="mt-3 text-sm leading-6 text-ink-200">{{ $lookalike->ai_reason ?: $alert?->ai_reason ?: 'A IA está analisando os prints. O parecer aparece aqui em instantes.' }}</p>
                @if ($held)
                    <p class="mt-2 text-xs text-ink-400">Se for golpe, marque em “Avaliar”: a IA aprende com a sua resposta e com o motivo.</p>
                @endif
            </section>
        @endif

        <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
            <div class="min-w-0 space-y-5">
                {{-- Prints da última visita, em cada aparelho --}}
                <section class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-semibold text-white">Como a página aparece</h2>
                        @if ($capture)
                            <p class="text-xs text-ink-400">Visitada em {{ $when($capture->captured_at) }}@if ($capture->http_status) · HTTP {{ $capture->http_status }}@endif · print ao acessar e depois de 10 s na página</p>
                        @endif
                    </div>

                    @if ($capture && $capture->devicesWithShots() !== [])
                        @include('alerts.lookalikes.partials.device-prints', ['capture' => $capture])
                        @if ($capture->final_url)
                            <p class="text-sm text-ink-400">Endereço final: <span class="font-mono break-all text-ink-200">{{ $capture->final_url }}</span></p>
                        @endif
                        @if ($capture->certificateProblem())
                            <p class="text-sm text-amber-200">Certificado HTTPS {{ $capture->certificateProblem() }}: quem visita veria um aviso do navegador. O Radar abriu a página mesmo assim.</p>
                        @endif
                        @if (! empty($capture->data['redirects']))
                            <p class="text-xs text-ink-400">Passou por: <span class="font-mono break-all">{{ implode(' → ', $capture->data['redirects']) }}</span></p>
                        @endif
                        @if ($explain)
                            <p class="text-sm text-ink-200">{{ $explain }}</p>
                        @endif
                    @else
                        <div class="grid place-items-center rounded-lg border border-dashed border-ink-700 bg-ink-950 px-6 py-10 text-center">
                            <p class="font-semibold text-ink-200">{{ ucfirst($lookalike->stateLabel()) }}</p>
                            @if ($explain)
                                <p class="mt-1 max-w-md text-sm text-ink-400">{{ $explain }}</p>
                            @endif
                            @if ($capture?->error || $lookalike->lastCapture?->error)
                                <p class="mt-2 font-mono text-xs text-ink-400">{{ $capture?->error ?? $lookalike->lastCapture?->error }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($capture?->title || $capture?->text_excerpt)
                        <details class="rounded-lg border border-ink-800 bg-ink-950 px-4 py-3 text-sm">
                            <summary class="cursor-pointer text-ink-200">Texto da página</summary>
                            @if ($capture->title)
                                <p class="mt-2 text-ink-400">Título: <span class="text-ink-200">{{ $capture->title }}</span></p>
                            @endif
                            @if ($capture->text_excerpt)
                                <p class="mt-2 leading-6 whitespace-pre-line text-ink-400">{{ \Illuminate\Support\Str::limit($capture->text_excerpt, 1200) }}</p>
                            @endif
                        </details>
                    @endif
                </section>

                {{-- Comparação com a página parecida do cliente --}}
                @if ($similar && $refPage && ($refShot = $refPage['shots'][$similar['device']] ?? null))
                    @php $moment = $capture->latestMoment($similar['device']); @endphp
                    <section class="space-y-3 rounded-xl border border-red-900/70 bg-ink-900 p-5">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="font-semibold text-white">Parece com o seu site</h2>
                            <span class="font-mono text-sm text-red-300">{{ $similar['score'] }}% parecido · {{ LookalikeCapture::DEVICES[$similar['device']] }}</span>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <figure class="space-y-1.5">
                                <img src="{{ route('alerts.lookalikes.reference', [$lookalike->watch, $similar['page'] + 1, $similar['device']]) }}" alt="Seu site" loading="lazy" class="max-h-96 w-full rounded-lg border border-ink-800 bg-white object-cover object-top">
                                <figcaption class="text-xs text-ink-400">Seu site · {{ $refPage['title'] ?: $refPage['url'] }}</figcaption>
                            </figure>
                            <figure class="space-y-1.5">
                                <img src="{{ route('alerts.lookalikes.shot', [$capture, $similar['device'], $moment === 'second' ? 'depois' : 'first']) }}" alt="{{ $lookalike->displayHost() }}" loading="lazy" class="max-h-96 w-full rounded-lg border border-ink-800 bg-white object-cover object-top">
                                <figcaption class="text-xs text-ink-400">{{ $lookalike->displayHost() }}</figcaption>
                            </figure>
                        </div>
                    </section>
                @endif

                {{-- Por que este nível --}}
                <section class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-semibold text-white">Por que este nível</h2>
                        <span class="font-mono text-sm text-ink-400">{{ $lookalike->score }} pontos</span>
                    </div>
                    <div class="relative h-1.5 rounded-full bg-ink-800" aria-hidden="true">
                        <div class="h-full rounded-full {{ ['critical' => 'bg-red-400', 'high' => 'bg-orange-400', 'watch' => 'bg-yellow-300'][$lookalike->risk] ?? 'bg-ink-400' }}" style="width: {{ max(2, $lookalike->score) }}%"></div>
                    </div>
                    @if ($lookalike->signals)
                        <ul class="divide-y divide-ink-850 text-sm">
                            @foreach (collect($lookalike->signals)->sortByDesc('points') as $signal)
                                <li class="flex items-center justify-between gap-3 py-1.5">
                                    <span class="{{ $signal['points'] > 0 ? 'text-ink-200' : 'text-ink-400' }}">{{ $signal['label'] }}</span>
                                    <span class="shrink-0 font-mono text-xs {{ $signal['points'] > 0 ? 'text-amber-200' : 'text-emerald-300' }}">{{ $signal['points'] > 0 ? '+' : '' }}{{ $signal['points'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-ink-400">Nenhum sinal de golpe até agora.</p>
                    @endif
                    <p class="text-xs leading-5 text-ink-400">
                        Sem página (sem site, estacionado, em branco), o domínio fica no máximo em “Vigiar”. Quando aparece um site, contam a cópia do seu (aparência, arquivos puxados do seu domínio, a marca no título) e o pedido de senha, cartão, CPF ou Pix, em qualquer aparelho.
                    </p>
                </section>

                {{-- Linha do tempo --}}
                @if ($captures->count() > 1)
                    <section class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
                        <h2 class="font-semibold text-white">Histórico das visitas</h2>
                        <ol class="space-y-3">
                            @foreach ($captures as $item)
                                <li class="grid grid-cols-[112px_1fr] items-center gap-3">
                                    @include('alerts.lookalikes.partials.thumb', ['lookalike' => $lookalike, 'capture' => $item])
                                    <div class="min-w-0 text-sm">
                                        <p class="flex flex-wrap items-center gap-2">
                                            <span class="text-ink-200">{{ $when($item->captured_at) }}</span>
                                            @if ($item->risk)
                                                @include('alerts.lookalikes.partials.risk', ['risk' => $item->risk])
                                            @endif
                                            @if ($item->keep && ! $loop->last)
                                                <span class="text-xs text-amber-200">mudou de nível</span>
                                            @endif
                                        </p>
                                        <p class="truncate text-ink-400">{{ LookalikeDomain::STATE_LABELS[$item->state] ?? $item->state }}@if ($item->title) · {{ $item->title }}@endif</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif
            </div>

            <aside class="space-y-5">
                <section class="space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5 text-sm">
                    <div class="flex items-baseline justify-between gap-2">
                        <h2 class="font-semibold text-white">Denúncia</h2>
                        <a href="{{ route('alerts.lookalikes.kit', $lookalike) }}" class="text-xs text-accent hover:underline">Abrir o kit</a>
                    </div>
                    @if ($reports->isEmpty())
                        <p class="text-ink-400">Nada enviado ainda. O kit traz a quem escrever, o texto pronto e os prints para anexar.</p>
                    @else
                        <ul class="space-y-1">
                            @foreach ($reports as $key => $report)
                                <li class="flex justify-between gap-2"><span class="text-ink-200">{{ \App\Services\Lookalike\AbuseKit::CHANNELS[$key] ?? $key }}</span><span class="text-ink-400">{{ \Illuminate\Support\Carbon::parse($report['at'])->timezone('America/Sao_Paulo')->format('d/m') }}</span></li>
                            @endforeach
                        </ul>
                        <p class="text-xs text-ink-400">{{ $lookalike->takenDown() ? 'O domínio saiu do ar depois da denúncia.' : 'O Radar abre o domínio todo dia e mostra aqui quando ele cair.' }}</p>
                    @endif
                </section>

                <section class="space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5 text-sm">
                    <h2 class="font-semibold text-white">Registro</h2>
                    <dl class="grid grid-cols-[96px_1fr] gap-x-3 gap-y-1.5">
                        <dt class="text-ink-400">Criado</dt>
                        <dd class="text-ink-200">{{ $lookalike->registered_at ? $lookalike->registered_at->format('d/m/Y').' ('.$lookalike->registeredAgo().')' : 'não informado' }}</dd>
                        @if (! empty($rdap['expires_at']))
                            <dt class="text-ink-400">Vence</dt>
                            <dd class="text-ink-200">{{ \Illuminate\Support\Carbon::parse($rdap['expires_at'])->format('d/m/Y') }}</dd>
                        @endif
                        <dt class="text-ink-400">Registrador</dt>
                        <dd class="text-ink-200">{{ $rdap['registrar'] ?? 'não informado' }}</dd>
                    </dl>
                </section>

                <section class="space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5 text-sm">
                    <h2 class="font-semibold text-white">DNS</h2>
                    <dl class="grid grid-cols-[96px_1fr] gap-x-3 gap-y-1.5">
                        <dt class="text-ink-400">Endereço</dt>
                        <dd class="font-mono text-xs break-all text-ink-200">{{ implode(', ', $lookalike->ips ?? []) ?: 'nenhum' }}</dd>
                        <dt class="text-ink-400">E-mail (MX)</dt>
                        <dd class="font-mono text-xs break-all {{ $lookalike->hasMx() ? 'text-amber-200' : 'text-ink-200' }}">{{ implode(', ', $lookalike->mx ?? []) ?: 'não recebe' }}</dd>
                        <dt class="text-ink-400">Servidores</dt>
                        <dd class="font-mono text-xs break-all text-ink-200">{{ implode(', ', $lookalike->ns ?? []) ?: '—' }}</dd>
                        <dt class="text-ink-400">Hospedagem</dt>
                        <dd class="text-ink-200">{{ $hosting['org'] ?? $hosting['network'] ?? 'não identificada' }}</dd>
                    </dl>
                    <p class="pt-1 text-xs text-ink-400">Relido em {{ $when($lookalike->last_checked_at) ?? '—' }}.</p>
                </section>
            </aside>
        </div>
    </div>
@endsection
