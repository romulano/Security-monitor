{{--
    Um aviso do Radar na lista de Alertas (domínio novo já em Crítico ou Alto, ou que subiu de nível), com os painéis
    "Motivo" e "Avaliar" ("É golpe", "É nosso", "Não é ameaça" e o motivo, que a IA lê nos próximos achados).
    Espera $alert (com lookalike), $watch (o Radar do domínio), $aiCut e $many (mais de um Radar: mostra de qual é).
    Opcionais: $prefix (prefixo dos ids dos painéis) e $badges.
--}}
@php
    use App\Models\LookalikeDomain;

    $lookalike = $alert->lookalike;
    $key = ($prefix ?? '').$alert->id;
    $held = $alert->status === 'held';
    $risk = LookalikeDomain::RISK_LABELS[$alert->risk] ?? $alert->risk;
    $why = ($alert->kind === 'raised' && $alert->previous_risk
        ? 'Subiu de '.(LookalikeDomain::RISK_LABELS[$alert->previous_risk] ?? $alert->previous_risk).' para '.$risk
        : 'Apareceu já em '.$risk).' · parecido com '.$watch?->domain?->domain;
    $reason = $alert->ai_reason ?: $lookalike->ai_reason;
    [$whyTitle, $whyTone, $whyText] = match (true) {
        $held => ['Segurado pela IA', 'text-violet-300', $reason ?: 'A IA julgou que este domínio não é golpe contra a sua marca.'],
        $alert->ai_score !== null => ['Aprovado pela IA', 'text-emerald-300', $reason ?: 'A IA julgou que este domínio pode ser golpe contra a sua marca.'],
        $alert->status === 'ai_review' => ['Em análise pela IA', 'text-ink-200', 'A IA está analisando os prints. O parecer aparece aqui em instantes.'],
        default => ['Sem parecer da IA', 'text-ink-200', $reason ?: 'A IA não respondeu, e o aviso saiu assim mesmo.'],
    };
    // Mesma regra da lista do Radar: se a IA julgou que não é golpe (e o cliente não disse o contrário), sem atalho para denunciar.
    $threat = in_array($lookalike->risk, ['critical', 'high'], true) || $lookalike->verdict === 'threat';
    $report = $threat && ! $lookalike->isDismissed() && ($lookalike->verdict === 'threat' || ! $held);
    $reasons = collect($lookalike->signals ?? [])->where('points', '>', 0)->sortByDesc('points')->take(4)->pluck('label');
@endphp

<div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-850 px-5 py-3.5 first:border-t-0 {{ $held ? 'bg-violet-950/30 shadow-[inset_3px_0_0_var(--color-violet-500)]' : '' }}">
    @include('alerts.partials.row-when', ['at' => $alert->created_at, 'source' => 'radar'])
    <span class="flex shrink-0 flex-wrap items-center gap-1.5 {{ $badges ?? 'sm:w-48' }}">
        @include('alerts.lookalikes.partials.risk', ['risk' => $alert->risk])
        @if ($alert->ai_score !== null)
            <span title="Certeza da IA de que é golpe contra a sua marca (avisa acima de {{ $aiCut }}%)"
                  class="rounded border px-1.5 py-px font-mono text-[10px] font-medium {{ $held ? 'border-violet-700/70 bg-violet-950/60 text-violet-200' : 'border-emerald-800/70 bg-emerald-950/50 text-emerald-300' }}">IA {{ $alert->ai_score }}%</span>
        @endif
    </span>
    <div class="flex min-w-0 flex-[1_1_320px] flex-col gap-0.5">
        <a href="{{ route('alerts.lookalikes.show', $lookalike) }}" class="font-mono text-[15px] font-semibold break-all text-white hover:text-accent">{{ $lookalike->displayHost() }}</a>
        <span class="text-sm text-ink-400">
            @if ($held)
                <span class="mr-0.5 rounded border border-violet-700/70 bg-violet-950/60 px-1.5 py-px text-xs text-violet-200">Segurado pela IA</span>
            @endif
            @if ($lookalike->takenDown())
                <span class="mr-0.5 rounded border border-emerald-800/70 bg-emerald-950/50 px-1.5 py-px text-xs text-emerald-300">Fora do ar após denúncia</span>
            @elseif ($lookalike->reported())
                <span class="mr-0.5 rounded border border-sky-800/70 bg-sky-950/50 px-1.5 py-px text-xs text-sky-300">Denunciado</span>
            @endif
            @if ($many && $watch)
                <span class="rounded border border-ink-700 px-1.5 py-px text-xs text-ink-200">{{ $watch->domain?->domain }}</span>
            @endif
            {{ $why }} · {{ $held ? 'não enviado' : $alert->statusLabel() }}
        </span>
    </div>
    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
        <button type="button" popovertarget="motivo-{{ $key }}" data-why
                class="inline-flex min-h-9 items-center gap-1.5 rounded-md border px-2.5 text-sm {{ $held ? 'border-violet-700/70 text-violet-200 hover:border-violet-400' : 'border-ink-700 text-ink-200 hover:border-ink-400' }}">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            Motivo
        </button>
        @if ($report)
            <a href="{{ route('alerts.lookalikes.kit', $lookalike) }}"
               class="inline-flex min-h-9 items-center rounded-md border border-red-900/80 px-2.5 text-sm text-red-200 hover:border-red-500">Denunciar</a>
        @endif
        {{-- Os botões abrem o painel "Avaliar", já com a opção clicada marcada. --}}
        @if ($lookalike->verdict)
            <span class="text-sm {{ $lookalike->verdict === 'threat' ? 'text-red-300' : 'text-ink-400' }}">marcado como “{{ LookalikeDomain::VERDICT_LABELS[$lookalike->verdict] }}”</span>
            <button type="button" popovertarget="voto-{{ $key }}" class="min-h-9 rounded-md px-2 text-sm text-ink-400 underline-offset-2 hover:text-white hover:underline">Editar</button>
        @elseif ($alert->status !== 'ai_review')
            @foreach (['golpe' => 'É golpe', 'seguro' => 'Não é ameaça'] as $value => $label)
                <button type="button" popovertarget="voto-{{ $key }}" data-vote="{{ $value }}"
                        class="min-h-9 rounded-md border border-ink-700 px-2.5 text-sm text-ink-200 hover:border-ink-400">{{ $label }}</button>
            @endforeach
        @endif
    </div>

    <div id="motivo-{{ $key }}" popover data-float aria-label="Motivo do aviso"
         class="w-[min(24rem,calc(100vw-2rem))] rounded-xl border border-ink-800 bg-ink-900 p-4 text-left text-ink-200 shadow-2xl shadow-black/60">
        <div class="flex items-start justify-between gap-3">
            <p class="font-semibold {{ $whyTone }}">{{ $whyTitle }}</p>
            <button type="button" popovertarget="motivo-{{ $key }}" popovertargetaction="hide" aria-label="Fechar"
                    class="-mt-1.5 -mr-1.5 grid size-8 place-items-center rounded text-lg text-ink-400 hover:text-white">×</button>
        </div>
        @if ($alert->ai_score !== null)
            <div class="mt-3">
                <div class="flex justify-between text-xs text-ink-400">
                    <span>Certeza da IA de que é golpe contra a sua marca</span>
                    <span class="font-mono font-semibold {{ $held ? 'text-violet-200' : 'text-emerald-300' }}">{{ $alert->ai_score }}%</span>
                </div>
                <div class="relative mt-1.5 h-1.5 rounded-full bg-ink-800">
                    <div class="h-full rounded-full {{ $held ? 'bg-violet-400' : 'bg-emerald-400' }}" style="width: {{ max(2, $alert->ai_score) }}%"></div>
                    <div class="absolute -top-1 h-3.5 w-0.5 rounded bg-ink-200" style="left: {{ $aiCut }}%"></div>
                </div>
                <p class="mt-1 text-[11px] text-ink-400">Avisa acima de {{ $aiCut }}% (a marca na barra).</p>
            </div>
        @endif
        <p class="mt-3 text-sm leading-6">{{ $whyText }}</p>
        @if ($reasons->isNotEmpty())
            <p class="mt-3 text-[11px] tracking-widest text-ink-400 uppercase">O que a varredura viu</p>
            <ul class="mt-1 space-y-0.5 text-sm">
                @foreach ($reasons as $line)
                    <li>· {{ $line }}</li>
                @endforeach
            </ul>
        @endif
        <p class="mt-3 text-xs text-ink-400">Situação: {{ $alert->statusLabel() }}</p>
        <p class="mt-3 border-t border-ink-800 pt-3 text-xs leading-5 text-ink-400">
            <a href="{{ route('alerts.lookalikes.show', $lookalike) }}" class="text-accent hover:underline">Ver os prints e o domínio completo</a>
            · A sua avaliação ensina a IA a julgar os próximos domínios parecidos.
        </p>
    </div>

    <form id="voto-{{ $key }}" popover data-float method="POST" action="{{ route('alerts.lookalikes.verdict', $lookalike) }}" aria-label="Avaliar domínio"
          class="w-[min(24rem,calc(100vw-2rem))] rounded-xl border border-ink-800 bg-ink-900 p-4 text-left text-ink-200 shadow-2xl shadow-black/60">
        @csrf
        @include('alerts.lookalikes.partials.verdict-fields', ['lookalike' => $lookalike, 'id' => 'nota-'.$key])
        <div class="mt-4 flex justify-between gap-2">
            @if ($lookalike->verdict)
                <button name="acao" value="desfazer" formnovalidate class="min-h-10 rounded-lg px-3 text-sm text-ink-400 hover:text-white">Desfazer</button>
            @else
                <span></span>
            @endif
            <div class="flex gap-2">
                <button type="button" popovertarget="voto-{{ $key }}" popovertargetaction="hide"
                        class="min-h-10 rounded-lg border border-ink-800 px-3 text-sm text-ink-200 hover:border-ink-700">Cancelar</button>
                <button class="min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar avaliação</button>
            </div>
        </div>
    </form>
</div>
