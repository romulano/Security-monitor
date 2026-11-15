{{--
    Primeira coluna de uma linha de alerta: a data e, em Alertas (que junta as três fontes), a fonte com a cor dela.
    Espera $at e $source (news, radar, github; nulo nas páginas de cada fonte).
--}}
<span class="flex w-24 shrink-0 flex-col text-sm text-ink-400">
    {{ $at->timezone('America/Sao_Paulo')->format('d/m H:i') }}
    @if ($source)
        <span class="mt-0.5 inline-flex items-center gap-1.5 text-xs text-ink-200">
            <span class="size-2 shrink-0 rounded-full {{ ['news' => 'bg-accent', 'radar' => 'bg-teal-400', 'github' => 'bg-indigo-300'][$source] }}" aria-hidden="true"></span>{{ ['news' => 'Notícias', 'radar' => 'Radar', 'github' => 'GitHub'][$source] }}
        </span>
    @endif
</span>
