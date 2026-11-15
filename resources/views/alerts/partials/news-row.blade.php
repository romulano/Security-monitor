{{--
    Uma notícia na lista, com os painéis "Motivo" e "Avaliar" e o botão de reenvio. Usada em Notícias e em Alertas.
    Espera $match (com case e profile), $aiCut, $receivers (apps que recebem notícias), $apps (o "Slack e Discord"
    da situação de envio) e $many (mais de um alerta de notícias: mostra de qual é). Opcionais: $prefix (prefixo dos
    ids dos painéis, para não colidir com outra fonte na mesma página), $hub (mostra a fonte junto da data) e $badges.
--}}
@php
    $key = ($prefix ?? '').$match->id;
    $case = $match->case;
    $held = $match->heldByAi();
    $scored = $match->ai_relevance !== null;
    // Parecer dado depois do envio (alertas de antes do juiz): segurado, mas já tinha saído.
    $outcome = $held
        ? ($match->delivery_status === 'sent' ? 'enviado antes do parecer da IA' : 'não enviado')
        : $match->deliveryLabel($apps);
    // Cabeçalho e explicação do painel "Motivo", conforme o que a IA fez.
    [$whyTitle, $whyTone, $whyText] = match (true) {
        $held => ['Segurado pela IA', 'text-violet-300', $match->ai_reason ?: 'A IA julgou que esta notícia não interessa a você.'],
        $scored => ['Aprovado pela IA', 'text-emerald-300', $match->ai_reason ?: 'A IA julgou que esta notícia interessa a você.'],
        $match->delivery_note === 'ai_review' => ['Em análise pela IA', 'text-ink-200', 'A IA está avaliando se esta notícia interessa a você. O parecer aparece aqui em instantes.'],
        $match->ai_reviewed_at !== null => ['Sem parecer da IA', 'text-ink-200', $match->ai_reason ?: 'A IA não respondeu, e o alerta saiu assim mesmo.'],
        $match->hasBrandReason() => ['Cita a sua empresa', 'text-accent', 'Notícia que cita a sua empresa sempre chega, sem passar pela IA.'],
        default => ['Sem parecer da IA', 'text-ink-200', 'Este alerta é de antes do parecer da IA (ou de quando ele estava desligado).'],
    };
@endphp
<div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-850 px-5 py-3.5 first:border-t-0 {{ $held ? 'bg-violet-950/30 shadow-[inset_3px_0_0_var(--color-violet-500)]' : '' }}">
    @include('alerts.partials.row-when', ['at' => $match->matched_at, 'source' => ($hub ?? false) ? 'news' : null])
    {{-- Largura fixa no desktop: com ou sem selo da IA, os títulos ficam alinhados. --}}
    <span class="flex shrink-0 items-center gap-1.5 {{ $badges ?? 'sm:w-32' }}">
        <x-criticity :level="$match->criticity" size="xs" />
        @if ($scored)
            <span title="Certeza da IA de que esta notícia interessa a você (envia acima de {{ $aiCut }}%)"
                  class="rounded border px-1.5 py-px font-mono text-[10px] font-medium {{ $held ? 'border-violet-700/70 bg-violet-950/60 text-violet-200' : 'border-emerald-800/70 bg-emerald-950/50 text-emerald-300' }}">IA {{ $match->ai_relevance }}%</span>
        @endif
    </span>
    <div class="flex min-w-0 flex-[1_1_320px] flex-col gap-0.5">
        <a href="{{ route('feed.show', $case->uuid) }}" class="font-semibold text-white hover:text-accent">
            {{ $match->profile->language === 'pt' ? ($case->title_pt ?: $case->title) : $case->title }}
        </a>
        <span class="text-sm text-ink-400">
            @if ($held)
                <span class="mr-0.5 rounded border border-violet-700/70 bg-violet-950/60 px-1.5 py-px text-xs text-violet-200">Segurado pela IA</span>
            @endif
            @if ($many)
                <span class="rounded border border-ink-700 px-1.5 py-px text-xs text-ink-200">{{ $match->profile->displayName() }}</span>
            @endif
            @include('alerts.partials.sector-badge', ['item' => $match->profile])
            {{ $match->kind === 'case_update' ? 'Atualização · ' : '' }}Por quê: <span class="text-amber-200">{{ $match->reasonTerms() }}</span>
            · {{ $outcome }}
        </span>
    </div>
    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
        <button type="button" popovertarget="motivo-{{ $key }}" data-why
                class="inline-flex min-h-9 items-center gap-1.5 rounded-md border px-2.5 text-sm {{ $held ? 'border-violet-700/70 text-violet-200 hover:border-violet-400' : 'border-ink-700 text-ink-200 hover:border-ink-400' }}">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            Motivo
        </button>
        @if ($receivers->isNotEmpty() && $match->delivery_status !== 'pending')
            <form method="POST" action="{{ route('alerts.resend', $match) }}">
                @csrf
                <button class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-ink-700 px-2.5 text-sm text-ink-200 hover:border-accent hover:text-white"
                        title="Manda este alerta {{ $held && $match->delivery_status !== 'sent' ? 'mesmo assim' : 'de novo' }} para o {{ $receivers->map->appName()->implode(' e o ') }}">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                    {{ $held && $match->delivery_status !== 'sent' ? 'Enviar' : 'Reenviar' }}
                </button>
            </form>
        @endif
        {{-- Os dois botões abrem o painel "Avaliar" (voto + motivo opcional), já com a opção clicada marcada. --}}
        @if ($match->feedback)
            <span class="text-sm {{ $match->feedback === 'relevant' ? 'text-emerald-300' : 'text-ink-400' }}">
                marcado como {{ $match->feedback === 'relevant' ? 'relevante' : 'não relevante' }}
                @if ($match->feedback_unaffected)
                    <span class="text-teal-300" title="Relevante, mas não atinge vocês: já estão protegidos">· <svg class="inline size-3.5 -translate-y-px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg> não nos atinge</span>
                @endif
            </span>
            <button type="button" popovertarget="voto-{{ $key }}"
                    class="min-h-9 rounded-md px-2 text-sm text-ink-400 underline-offset-2 hover:text-white hover:underline">Editar</button>
        @else
            @foreach (['relevant' => 'Relevante', 'irrelevant' => 'Não é relevante'] as $value => $label)
                <button type="button" popovertarget="voto-{{ $key }}" data-vote="{{ $value }}"
                        class="min-h-9 rounded-md border border-ink-700 px-2.5 text-sm text-ink-200 hover:border-ink-400">{{ $label }}</button>
            @endforeach
        @endif
    </div>

    {{-- Painel flutuante do botão "Motivo" (Popover API: fecha com Esc ou clique fora; o app.js posiciona junto ao botão). --}}
    <div id="motivo-{{ $key }}" popover data-float aria-label="Motivo do alerta"
         class="w-[min(24rem,calc(100vw-2rem))] rounded-xl border border-ink-800 bg-ink-900 p-4 text-left text-ink-200 shadow-2xl shadow-black/60">
        <div class="flex items-start justify-between gap-3">
            <p class="font-semibold {{ $whyTone }}">{{ $whyTitle }}</p>
            <button type="button" popovertarget="motivo-{{ $key }}" popovertargetaction="hide" aria-label="Fechar"
                    class="-mt-1.5 -mr-1.5 grid size-8 place-items-center rounded text-lg text-ink-400 hover:text-white">×</button>
        </div>
        @if ($scored)
            <div class="mt-3">
                <div class="flex justify-between text-xs text-ink-400">
                    <span>Certeza da IA de que interessa a você</span>
                    <span class="font-mono font-semibold {{ $held ? 'text-violet-200' : 'text-emerald-300' }}">{{ $match->ai_relevance }}%</span>
                </div>
                <div class="relative mt-1.5 h-1.5 rounded-full bg-ink-800">
                    <div class="h-full rounded-full {{ $held ? 'bg-violet-400' : 'bg-emerald-400' }}" style="width: {{ $match->ai_relevance }}%"></div>
                    <div class="absolute -top-1 h-3.5 w-0.5 rounded bg-ink-200" style="left: {{ $aiCut }}%"></div>
                </div>
                <p class="mt-1 text-[11px] text-ink-400">Envia acima de {{ $aiCut }}% (a marca na barra).</p>
            </div>
        @endif
        <p class="mt-3 text-sm leading-6">{{ $whyText }}</p>
        @if ($lines = $match->reasonLines())
            <p class="mt-3 text-[11px] tracking-widest text-ink-400 uppercase">Por que casou com o seu alerta</p>
            <ul class="mt-1 space-y-0.5 text-sm">
                @foreach ($lines as $line)
                    <li>· {{ $line }}</li>
                @endforeach
            </ul>
        @endif
        <p class="mt-3 text-xs text-ink-400">Situação: {{ $outcome }}</p>
        @if ($match->feedback)
            <p class="mt-3 text-[11px] tracking-widest text-ink-400 uppercase">Sua avaliação</p>
            <p class="mt-1 text-sm">
                <span class="{{ $match->feedback === 'relevant' ? 'text-emerald-300' : 'text-ink-200' }}">{{ $match->feedback === 'relevant' ? 'Relevante' : 'Não é relevante' }}</span>
                @if ($match->feedback_unaffected)
                    <span class="text-teal-300">· não nos atinge (já protegidos)</span>
                @endif
                @if ($match->feedback_note)
                    · “{{ $match->feedback_note }}”
                    @if ($match->feedback_note_flagged)
                        <span class="mt-1 block text-xs text-ink-400">Esse motivo não vai para a IA: parece uma instrução para ela.</span>
                    @endif
                @endif
            </p>
        @endif
        <p class="mt-3 border-t border-ink-800 pt-3 text-xs leading-5 text-ink-400">
            Marcar como “Relevante” ou “Não é relevante” ensina a IA a decidir os próximos alertas.
        </p>
    </div>

    {{-- Painel "Avaliar": o voto e o motivo opcional, que a IA lê nos próximos pareceres. --}}
    <form id="voto-{{ $key }}" popover data-float method="POST" action="{{ route('alerts.feedback', $match) }}" aria-label="Avaliar alerta"
          class="w-[min(22rem,calc(100vw-2rem))] rounded-xl border border-ink-800 bg-ink-900 p-4 text-left text-ink-200 shadow-2xl shadow-black/60">
        @csrf
        @include('alerts.partials.vote-fields', ['match' => $match, 'id' => 'nota-'.$key])
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" popovertarget="voto-{{ $key }}" popovertargetaction="hide"
                    class="min-h-10 rounded-lg border border-ink-800 px-3 text-sm text-ink-200 hover:border-ink-700">Cancelar</button>
            <button class="min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar avaliação</button>
        </div>
    </form>
</div>
