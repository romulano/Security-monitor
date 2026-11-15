{{-- Admin → Coletas: o motivo do erro em linguagem clara, se o artigo já foi refeito e, recolhido, o erro técnico (AnalysisAttempts::situation, ErrorReasons). --}}
@php
    $at = $situation['at']?->timezone('America/Sao_Paulo')->format('d/m H:i');
    $reason = $situation['reason']['text'] ?? null;
    [$text, $color] = match ($situation['state']) {
        'recovered' => ["refeito em {$at} e deu certo", 'text-emerald-400'],
        'failed_again' => ["refeito em {$at} e falhou de novo".($situation['failures'] > 1 ? " ({$situation['failures']} vezes)" : ''), 'text-red-400'],
        default => ['ainda não foi pedido para refazer', 'text-red-400'],
    };
@endphp

<div class="mt-0.5 text-[11px]">
    @if ($situation['state'] === 'recovered')
        <p class="{{ $color }}">{{ $text }}</p>
    @else
        <p class="text-[12px] font-semibold text-red-300">{{ $reason ?? 'Motivo ainda não explicado' }}</p>
        @unless ($reason)
            <p class="font-mono break-all text-ink-400">{{ \Illuminate\Support\Str::limit((string) $situation['error'], 160) }}</p>
        @endunless
        <p class="{{ $color }}">{{ $text }}</p>
    @endif

    <details class="mt-0.5 text-ink-400">
        <summary class="cursor-pointer text-ink-700 hover:text-ink-200">detalhes</summary>
        @if ($reason)
            <p class="mt-1">Motivo {{ $situation['reason']['source'] === 'ia' ? 'explicado pela IA (hermes)' : 'do mapa de erros conhecidos' }}: {{ $reason }}</p>
        @endif
        <p class="mt-1 font-mono break-all text-ink-200">{{ $situation['error'] }}</p>
        @if ($article->analysisAttempts->isNotEmpty())
            <ol class="mt-1 space-y-0.5">
                @foreach ($article->analysisAttempts as $attempt)
                    <li>
                        <span class="font-mono text-ink-700">{{ $attempt->created_at?->timezone('America/Sao_Paulo')->format('d/m H:i') }}</span>
                        {{ \App\Models\ArticleAttempt::TRIGGERS[$attempt->trigger] ?? $attempt->trigger }}
                        · {{ $attempt->driver ?? '—' }}
                        · <span class="{{ $attempt->failed() ? 'text-red-400' : 'text-emerald-400' }}">{{ \App\Models\ArticleAttempt::RESULTS[$attempt->result] ?? $attempt->result }}</span>
                        @if ($attempt->error)
                            <span class="break-all">· {{ \Illuminate\Support\Str::limit($attempt->error, 200) }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </details>
</div>
