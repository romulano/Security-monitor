<div class="overflow-x-auto rounded-lg border border-ink-800">
    <table class="w-full min-w-[46rem] text-sm">
        <thead class="bg-ink-900 text-left text-[11px] tracking-widest text-ink-700 uppercase">
            <tr>
                <th class="px-3 py-2 font-medium">Fonte</th>
                <th class="px-3 py-2 font-medium">Início</th>
                <th class="px-3 py-2 font-medium">Duração</th>
                <th class="px-3 py-2 font-medium">Gatilho</th>
                <th class="px-3 py-2 text-right font-medium">Itens</th>
                <th class="px-3 py-2 text-right font-medium">Novos</th>
                <th class="px-3 py-2 text-right font-medium">Dup.</th>
                <th class="px-3 py-2 text-right font-medium">Falhas</th>
                <th class="px-3 py-2 font-medium">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-ink-900">
            @forelse ($runs as $run)
                <tr class="hover:bg-ink-900/60">
                    <td class="px-3 py-2 font-mono text-xs text-ink-200">{{ $run->source?->host ?? '—' }}</td>
                    <td class="px-3 py-2 text-xs text-ink-400">{{ $run->started_at?->timezone('America/Sao_Paulo')->format('d/m H:i:s') }}</td>
                    <td class="px-3 py-2 font-mono text-xs text-ink-700">{{ $run->durationSeconds() !== null ? $run->durationSeconds().'s' : '—' }}</td>
                    <td class="px-3 py-2 text-xs text-ink-700">{{ $run->trigger }}</td>
                    <td class="px-3 py-2 text-right font-mono text-xs">{{ $run->items_found }}</td>
                    <td class="px-3 py-2 text-right font-mono text-xs text-accent">{{ $run->items_new }}</td>
                    <td class="px-3 py-2 text-right font-mono text-xs text-ink-700">{{ $run->items_duplicate }}</td>
                    <td class="px-3 py-2 text-right font-mono text-xs {{ $run->items_failed ? 'text-red-400' : 'text-ink-700' }}">{{ $run->items_failed }}</td>
                    <td class="px-3 py-2">
                        <span class="rounded px-1.5 py-0.5 text-[11px] {{ match ($run->status) {
                            'ok' => 'bg-emerald-950/60 text-emerald-300',
                            'failed' => 'bg-red-950/60 text-red-300',
                            default => 'bg-ink-850 text-ink-400',
                        } }}">{{ $run->status }}</span>
                        @if ($run->message)
                            <p class="mt-1 max-w-xs truncate text-[11px] text-red-400" title="{{ $run->message }}">{{ $run->message }}</p>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-sm text-ink-700">Nenhuma execução ainda.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
