{{-- A lista de eventos (Ataques) ou a linha do tempo de um IP (Por IP): o endereço é dado do visitante, então vai em <code>,
     escapado, nunca em link. Na linha do tempo, `source` diz se é página aberta ou evento; com $erasable (só na ficha),
     a página aberta tem o × que apaga só ela. --}}
@php
    $when = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->timezone('America/Sao_Paulo')->format('d/m H:i:s') : '—';
    $showIp = $showIp ?? true;
    $erasable = $erasable ?? false;
    $columns = ($showIp ? 5 : 4) + ($erasable ? 1 : 0);
@endphp
<div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
            <tr>
                <th class="px-4 py-2 font-medium">Quando</th>
                @if ($showIp)<th class="px-4 py-2 font-medium">IP</th>@endif
                <th class="px-4 py-2 font-medium">Pedido</th>
                <th class="px-4 py-2 text-right font-medium">Resposta</th>
                <th class="hidden px-4 py-2 font-medium md:table-cell">Navegador</th>
                @if ($erasable)<th class="px-2 py-2"><span class="sr-only">Apagar</span></th>@endif
            </tr>
        </thead>
        <tbody class="divide-y divide-ink-800">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-4 py-2 text-xs whitespace-nowrap text-ink-400">{{ $when($row->created_at) }}</td>
                    @if ($showIp)
                        <td class="px-4 py-2 font-mono text-xs break-all"><a href="{{ route('admin.accesses.ip', ['ip' => $row->ip]) }}" class="text-ink-200 hover:text-accent">{{ $row->ip }}</a></td>
                    @endif
                    <td class="min-w-40 max-w-md px-4 py-2 text-xs">
                        <code class="font-mono break-all text-ink-200">{{ $row->method }} {{ $row->path }}</code>
                        <span class="mt-0.5 block font-sans">
                            @if (($row->source ?? 'evento') === 'acesso')<span class="text-[10px] text-ink-700">página aberta</span>@endif
                            @if ($row->category)<span class="rounded bg-red-950/50 px-1 text-[10px] text-red-300">{{ \App\Support\AttackSignatures::label($row->category) }}</span>@endif
                            @if ($row->auth)<span class="text-[10px] text-ink-400">{{ \App\Services\Access\RejectedAttempts::REASONS[$row->auth] ?? $row->auth }}</span>@endif
                        </span>
                        @if ($row->referer)<span class="block text-[11px] break-all text-ink-700">de <code>{{ $row->referer }}</code></span>@endif
                    </td>
                    <td class="px-4 py-2 text-right font-mono text-xs {{ $row->status >= 400 ? 'text-red-300' : 'text-ink-400' }}">{{ $row->status ?? '—' }}</td>
                    <td class="hidden max-w-xs truncate px-4 py-2 text-xs text-ink-400 md:table-cell" title="{{ $row->user_agent }}">{{ $row->user_agent ?? '—' }}</td>
                    @if ($erasable)
                        <td class="px-2 py-2 text-right">
                            @if (($row->source ?? 'evento') === 'acesso')
                                <form method="POST" action="{{ route('admin.accesses.destroy', ['view' => $row->id, 'periodo' => $filters['periodo'], 'page' => $rows->currentPage() > 1 ? $rows->currentPage() : null]) }}"
                                      data-confirm="Apagar este acesso? Sai só este registro ({{ $when($row->created_at) }}, {{ \Illuminate\Support\Str::limit($row->path, 60) }}). Não dá para desfazer." data-confirm-ok="Apagar">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded px-1.5 text-sm text-ink-700 hover:bg-red-950/50 hover:text-red-300" aria-label="Apagar este acesso">×</button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $columns }}" class="px-4 py-4 text-sm text-ink-400">Nada nesse recorte.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $rows->links() }}</div>
