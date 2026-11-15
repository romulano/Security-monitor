{{-- Selo do nível de risco de um domínio do Radar. Vermelho e laranja como na criticidade; amarelo é "vigiar". --}}
@php
    $styles = [
        'critical' => 'border-red-800/70 bg-red-950/50 text-red-300',
        'high' => 'border-orange-800/70 bg-orange-950/50 text-orange-300',
        'watch' => 'border-yellow-800/60 bg-yellow-950/40 text-yellow-200',
        'low' => 'border-ink-700 bg-ink-850 text-ink-400',
    ];
@endphp
<span class="inline-flex items-center rounded border px-2 py-0.5 text-[11px] font-medium tracking-wide uppercase {{ $styles[$risk] ?? $styles['low'] }}">{{ \App\Models\LookalikeDomain::RISK_LABELS[$risk] ?? $risk }}</span>
