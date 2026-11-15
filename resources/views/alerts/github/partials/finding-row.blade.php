{{--
    Um achado na lista, com os painéis "Motivo" e "Avaliar". Usado na página do GitHub e em Alertas. Espera $finding,
    $aiCut e $receivers. Opcionais: $prefix (prefixo dos ids dos painéis), $hub (mostra a fonte junto da data e usa a
    data do aviso) e $badges.
--}}
@php
    $key = ($prefix ?? '').$finding->id;
    $held = $finding->heldByAi();
    $level = $finding->level();
    $voteTexts = [
        'legend' => 'Este achado é',
        'unaffectedHint' => 'Importa, mas já resolvemos: a chave foi trocada, o arquivo saiu ou não é nosso para corrigir.',
        'placeholder' => 'Ex.: esse repositório é de um parceiro, a chave é de teste e não dá acesso a nada.',
        'noteHint' => 'A IA lê o seu motivo para decidir os próximos avisos do GitHub. Não escreva o valor de chaves nem dados pessoais.',
    ];
@endphp

<div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-850 px-5 py-3.5 first:border-t-0 {{ $held ? 'bg-violet-950/30 shadow-[inset_3px_0_0_var(--color-violet-500)]' : '' }}">
    @include('alerts.partials.row-when', ['at' => ($hub ?? false) ? ($finding->notified_at ?? $finding->first_seen_at) : $finding->first_seen_at, 'source' => ($hub ?? false) ? 'github' : null])
    <span class="flex shrink-0 flex-wrap items-center gap-1.5 {{ $badges ?? 'sm:w-48' }}">
        @if ($level === 'none')
            <span class="inline-flex rounded border border-ink-800 px-1.5 py-px text-[10px] font-medium tracking-wide text-ink-400 uppercase">nenhum</span>
        @else
            <x-criticity :level="$level" size="xs" />
        @endif
        @if ($finding->triage_score !== null)
            <span title="Certeza da classificação de que é um achado real" class="rounded border border-sky-900/70 bg-sky-950/40 px-1.5 py-px font-mono text-[10px] font-medium text-sky-300">achado {{ $finding->triage_score }}%</span>
        @endif
        @if ($finding->ai_score !== null)
            <span title="Certeza da IA de que interessa a você (envia acima de {{ $aiCut }}%)"
                  class="rounded border px-1.5 py-px font-mono text-[10px] font-medium {{ $held ? 'border-violet-700/70 bg-violet-950/60 text-violet-200' : 'border-emerald-800/70 bg-emerald-950/50 text-emerald-300' }}">IA {{ $finding->ai_score }}%</span>
        @endif
    </span>
    <div class="flex min-w-0 flex-[1_1_320px] flex-col gap-0.5">
        <a href="{{ route('alerts.github.show', $finding) }}" class="truncate font-mono text-sm font-semibold text-white hover:text-accent" title="{{ $finding->location() }}">{{ $finding->location() }}</a>
        <span class="text-sm text-ink-400">
            @if ($held)
                <span class="mr-0.5 rounded border border-violet-700/70 bg-violet-950/60 px-1.5 py-px text-xs text-violet-200">Segurado pela IA</span>
            @endif
            @if ($finding->gone_at)
                <span class="mr-0.5 rounded border border-emerald-800/70 bg-emerald-950/40 px-1.5 py-px text-xs text-emerald-300">removido do GitHub em {{ $finding->gone_at->timezone('America/Sao_Paulo')->format('d/m') }}</span>
            @endif
            {{ $finding->kindLabel() }}@if ($finding->terms): <span class="text-amber-200">{{ implode(', ', array_slice($finding->terms, 0, 3)) }}</span>@endif
            · {{ $finding->statusLabel() }}
        </span>
    </div>
    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
        <button type="button" popovertarget="motivo-{{ $key }}" data-why
                class="inline-flex min-h-9 items-center gap-1.5 rounded-md border px-2.5 text-sm {{ $held ? 'border-violet-700/70 text-violet-200 hover:border-violet-400' : 'border-ink-700 text-ink-200 hover:border-ink-400' }}">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            Motivo
        </button>
        {{-- Reenviar é configurar (R14): o membro vê e vota, mas não dispara os canais de quem criou. --}}
        @if ($receivers->isNotEmpty() && $level !== 'none' && ! in_array($finding->status, ['triage', 'ai_review', 'queued'], true)
            && ($canManage ?? app(\App\Services\Company\Visibility::class)->manages(auth()->user(), $finding)))
            <form method="POST" action="{{ route('alerts.github.resend', $finding) }}">
                @csrf
                <button class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-ink-700 px-2.5 text-sm text-ink-200 hover:border-accent hover:text-white"
                        title="Manda este achado {{ $finding->status === 'sent' ? 'de novo' : 'mesmo assim' }} para o {{ $receivers->map->appName()->implode(' e o ') }}">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                    {{ $finding->status === 'sent' ? 'Reenviar' : 'Enviar' }}
                </button>
            </form>
        @endif
        @if ($finding->feedback)
            <span class="text-sm {{ $finding->feedback === 'relevant' ? 'text-emerald-300' : 'text-ink-400' }}">
                marcado como {{ $finding->feedback === 'relevant' ? 'relevante' : 'não relevante' }}
                @if ($finding->feedback_unaffected)<span class="text-teal-300">· já resolvido</span>@endif
            </span>
            <button type="button" popovertarget="voto-{{ $key }}" class="min-h-9 rounded-md px-2 text-sm text-ink-400 underline-offset-2 hover:text-white hover:underline">Editar</button>
        @elseif ($finding->status !== 'triage')
            @foreach (['relevant' => 'Relevante', 'irrelevant' => 'Não é relevante'] as $value => $label)
                <button type="button" popovertarget="voto-{{ $key }}" data-vote="{{ $value }}"
                        class="min-h-9 rounded-md border border-ink-700 px-2.5 text-sm text-ink-200 hover:border-ink-400">{{ $label }}</button>
            @endforeach
        @endif
    </div>

    <div id="motivo-{{ $key }}" popover data-float aria-label="Motivo do achado"
         class="w-[min(26rem,calc(100vw-2rem))] rounded-xl border border-ink-800 bg-ink-900 p-4 text-left text-ink-200 shadow-2xl shadow-black/60">
        <div class="flex items-start justify-between gap-3">
            <p class="font-semibold {{ $held ? 'text-violet-300' : 'text-white' }}">{{ ucfirst($finding->statusLabel()) }}</p>
            <button type="button" popovertarget="motivo-{{ $key }}" popovertargetaction="hide" aria-label="Fechar"
                    class="-mt-1.5 -mr-1.5 grid size-8 place-items-center rounded text-lg text-ink-400 hover:text-white">×</button>
        </div>
        <div class="mt-3">@include('alerts.github.partials.stages', ['finding' => $finding])</div>
        @if ($finding->signals)
            <p class="mt-4 text-[11px] tracking-widest text-ink-400 uppercase">O que a varredura viu</p>
            <ul class="mt-1 space-y-0.5 text-sm">
                @foreach (array_slice($finding->signals, 0, 6) as $signal)
                    <li>· {{ $signal['label'] }}</li>
                @endforeach
            </ul>
        @endif
        <p class="mt-3 border-t border-ink-800 pt-3 text-xs leading-5 text-ink-400">
            <a href="{{ route('alerts.github.show', $finding) }}" class="text-accent hover:underline">Ver o achado completo</a>
            · Marcar como “Relevante” ou “Não é relevante” ensina a IA a decidir os próximos avisos.
        </p>
    </div>

    <form id="voto-{{ $key }}" popover data-float method="POST" action="{{ route('alerts.github.feedback', $finding) }}" aria-label="Avaliar achado"
          class="w-[min(22rem,calc(100vw-2rem))] rounded-xl border border-ink-800 bg-ink-900 p-4 text-left text-ink-200 shadow-2xl shadow-black/60">
        @csrf
        @include('alerts.partials.vote-fields', ['match' => $finding, 'id' => 'nota-'.$key] + $voteTexts)
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" popovertarget="voto-{{ $key }}" popovertargetaction="hide"
                    class="min-h-10 rounded-lg border border-ink-800 px-3 text-sm text-ink-200 hover:border-ink-700">Cancelar</button>
            <button class="min-h-10 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar avaliação</button>
        </div>
    </form>
</div>
