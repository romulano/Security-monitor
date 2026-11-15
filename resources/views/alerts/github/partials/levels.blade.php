{{--
    O que cada nível e cada porcentagem significam no monitoramento do GitHub.
    Usado no passo do nível mínimo, na revisão e na página dos achados.
--}}
@php use App\Models\GithubFinding; @endphp

<div class="grid gap-4 lg:grid-cols-[3fr_2fr]">
    <div>
        <p class="text-[11px] tracking-widest text-ink-400 uppercase">Os níveis (etapa 1: classificação da IA)</p>
        <ul class="mt-2 space-y-2.5">
            @foreach (GithubFinding::LEVELS as $level => $info)
                <li class="flex gap-3">
                    <x-criticity :level="$level" size="xs" class="mt-0.5 w-16 shrink-0 justify-center self-start" />
                    <span class="text-sm">
                        <span class="font-semibold text-white">{{ $info['title'] }}.</span>
                        <span class="text-ink-400">{{ $info['desc'] }}</span>
                    </span>
                </li>
            @endforeach
            <li class="flex gap-3">
                <span class="mt-0.5 inline-flex w-16 shrink-0 self-start justify-center rounded border border-ink-800 px-1.5 py-px text-[10px] font-medium tracking-wide text-ink-400 uppercase">nenhum</span>
                <span class="text-sm"><span class="font-semibold text-white">Não é achado.</span> <span class="text-ink-400">Outra empresa com o mesmo nome, coincidência ou exemplo. Fica em “Descartados”.</span></span>
            </li>
        </ul>
    </div>
    <div class="space-y-4">
        <div>
            <p class="text-[11px] tracking-widest text-ink-400 uppercase">“Achado NN%”: certeza da classificação</p>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach (GithubFinding::CONFIDENCE as [$label, $range, $desc])
                    <li class="flex gap-2"><span class="w-16 shrink-0 font-mono text-ink-200">{{ $range }}%</span>
                        <span><span class="font-semibold text-white">{{ $label }}.</span> <span class="text-ink-400">{{ $desc }}</span></span></li>
                @endforeach
            </ul>
        </div>
        <div>
            <p class="text-[11px] tracking-widest text-ink-400 uppercase">“IA NN%”: parecer de envio (etapa 2)</p>
            <p class="mt-2 text-sm text-ink-400">
                Só para o que é achado no nível que você escolheu: a chance de você querer o aviso agora, aprendendo com as suas avaliações.
                Sai acima de <span class="font-mono text-ink-200">{{ app(\App\Services\Github\GithubJudge::class)->threshold() }}%</span>; no corte ou abaixo, fica
                <span class="text-violet-300">segurado pela IA</span>, visível aqui.
            </p>
        </div>
    </div>
</div>
