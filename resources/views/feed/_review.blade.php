{{-- Só o admin: o que o revisor do relatório disse da versão no ar e das anteriores. --}}
@php
    $field = fn (?string $name) => $name ?? 'texto todo';
    $when = fn ($at) => \Carbon\Carbon::parse($at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i');
    $current = $review['current'];
    $pending = $current['status'] === \App\Models\ReportReview::PENDING;
@endphp
<div class="mb-5 max-w-[68ch] rounded-lg border px-3 py-2.5 text-xs leading-5 {{ $pending ? 'border-amber-800/60 bg-amber-950/30 text-amber-200' : 'border-ink-800 bg-ink-900 text-ink-400' }}">
    <p>
        <strong class="font-semibold {{ $pending ? '' : 'text-ink-200' }}">{{ $current['label'] }}</strong>
        · {{ $current['mode'] }} {{ $current['origin'] }}, {{ $when($current['at']) }}
        · {{ $current['adjustments'] }} ajuste(s), {{ $current['calls'] }} chamada(s) a mais
        @if ($current['reason']) · {{ $current['reason'] }}@endif
    </p>
    @if ($pending)
        <p class="mt-1">A matéria saiu com a última versão. O revisor apontou, no último parecer:</p>
    @endif
    @if ($pending && $current['problems'])
        <ul class="mt-1 list-disc space-y-0.5 pl-4">
            @foreach ($current['problems'] as $problem)
                <li><span class="font-mono">{{ $field($problem['field']) }}</span>@if ($problem['excerpt']) — “{{ $problem['excerpt'] }}”@endif: {{ $problem['instruction'] }}</li>
            @endforeach
        </ul>
    @endif
    @if ($current['rounds'] || $review['history'])
        <details class="mt-1.5">
            <summary class="cursor-pointer text-ink-400 hover:text-ink-200">Pareceres e versões anteriores</summary>
            <ol class="mt-1 list-decimal space-y-0.5 pl-4 text-ink-400">
                @foreach ($current['rounds'] as $round)
                    <li>
                        {{ $round['verdict'].($round['scope'] ? ' ('.$round['scope'].')' : '').($round['note'] ? ': '.$round['note'] : '') }}
                        @foreach ($round['problems'] as $problem)
                            <br><span class="font-mono">{{ $field($problem['field']) }}</span>: {{ $problem['instruction'] }}
                        @endforeach
                    </li>
                @endforeach
            </ol>
            @foreach ($review['history'] as $old)
                <p class="mt-1 text-ink-700">{{ $old['label'] }} · {{ $old['mode'] }} {{ $old['origin'] }}, {{ $when($old['at']) }} · {{ $old['adjustments'] }} ajuste(s)@if ($old['reason']) · {{ $old['reason'] }}@endif</p>
            @endforeach
        </details>
    @endif
    <p class="mt-1 text-[11px] text-ink-700">Só o admin vê este quadro.</p>
</div>
