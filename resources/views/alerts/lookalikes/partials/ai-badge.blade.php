{{-- Selo do parecer da IA (como nos alertas): verde se o aviso saiu, violeta se a IA segurou. Recebe $lookalike. --}}
@if ($lookalike->ai_score !== null)
    @php $held = $lookalike->heldByAi(); @endphp
    <span title="{{ $held ? 'A IA segurou o aviso: ' : 'Certeza da IA de que é golpe: ' }}{{ $lookalike->ai_reason }}"
          class="rounded border px-1.5 py-px font-mono text-[10px] font-medium {{ $held ? 'border-violet-700/70 bg-violet-950/60 text-violet-200' : 'border-emerald-800/70 bg-emerald-950/50 text-emerald-300' }}">IA {{ $lookalike->ai_score }}%</span>
@endif
