{{--
    As duas análises da IA de um achado, com as barras. Espera $finding e $aiCut.
    Usado no painel "Motivo" da lista e na tela de detalhe.
--}}
@php
    use App\Models\GithubFinding;

    $held = $finding->heldByAi();
    $level = $finding->level();
@endphp

<div class="space-y-4">
    <div>
        <p class="text-[11px] tracking-widest text-ink-400 uppercase">Etapa 1 · classificação</p>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
            @if ($level === 'none')
                <span class="rounded border border-ink-800 px-1.5 py-px text-[10px] font-medium tracking-wide text-ink-400 uppercase">não é achado</span>
            @else
                <x-criticity :level="$level" size="xs" />
                <span class="text-ink-200">{{ GithubFinding::LEVELS[$level]['title'] }}</span>
            @endif
        </p>
        @if ($finding->triage_score !== null)
            <div class="mt-2">
                <div class="flex justify-between text-xs text-ink-400">
                    <span>Certeza de ser um achado real · {{ GithubFinding::confidenceLabel($finding->triage_score) }}</span>
                    <span class="font-mono font-semibold text-ink-200">{{ $finding->triage_score }}%</span>
                </div>
                <div class="relative mt-1.5 h-1.5 rounded-full bg-ink-800">
                    <div class="h-full rounded-full bg-sky-400" style="width: {{ $finding->triage_score }}%"></div>
                    <div class="absolute -top-1 h-3.5 w-0.5 rounded bg-ink-200" style="left: {{ \App\Services\Github\GithubTriage::MIN_CONFIDENCE }}%" title="abaixo daqui, não é achado"></div>
                </div>
            </div>
        @endif
        <p class="mt-2 text-sm leading-6 text-ink-200">
            {{ $finding->triage_reason ?: ($finding->triage_at ? 'Nível pelas regras automáticas (a classificação da IA estava desligada).' : 'A IA ainda vai classificar este achado.') }}
        </p>
    </div>

    <div class="border-t border-ink-800 pt-4">
        <p class="text-[11px] tracking-widest text-ink-400 uppercase">Etapa 2 · parecer de envio</p>
        @if ($finding->ai_score !== null)
            <div class="mt-2">
                <div class="flex justify-between text-xs text-ink-400">
                    <span>Certeza da IA de que interessa a você</span>
                    <span class="font-mono font-semibold {{ $held ? 'text-violet-200' : 'text-emerald-300' }}">{{ $finding->ai_score }}%</span>
                </div>
                <div class="relative mt-1.5 h-1.5 rounded-full bg-ink-800">
                    <div class="h-full rounded-full {{ $held ? 'bg-violet-400' : 'bg-emerald-400' }}" style="width: {{ $finding->ai_score }}%"></div>
                    <div class="absolute -top-1 h-3.5 w-0.5 rounded bg-ink-200" style="left: {{ $aiCut }}%"></div>
                </div>
                <p class="mt-1 text-[11px] text-ink-400">Envia acima de {{ $aiCut }}% (a marca na barra).</p>
            </div>
            <p class="mt-2 text-sm leading-6 {{ $held ? 'text-violet-200' : 'text-ink-200' }}">{{ $finding->ai_reason }}</p>
        @else
            <p class="mt-1 text-sm text-ink-400">
                @switch($finding->status)
                    @case('discarded') Não rodou: a classificação disse que não é achado. @break
                    @case('below_level') Não rodou: o nível ficou abaixo do que você escolheu ({{ $finding->monitor->levelLabel() }}). @break
                    @case('triage') Espera a classificação. @break
                    @case('ai_review') A IA está avaliando se este achado merece aviso. @break
                    @default {{ $finding->ai_reason ?: 'Sem parecer da IA (desligado no momento): o achado saiu pela classificação.' }}
                @endswitch
            </p>
        @endif
    </div>
</div>
