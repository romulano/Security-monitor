{{-- Últimos envios de um canal. Recebe $channel e $deliveries; no webhook, também o código HTTP e o tempo de resposta. --}}
@php $technical = $channel->isWebhook(); @endphp
<section class="overflow-hidden rounded-xl border border-ink-800 bg-ink-900">
    <div class="border-b border-ink-800 px-5 py-3"><h2 class="font-semibold text-white">Últimos envios</h2></div>
    @if ($deliveries->isEmpty())
        <p class="px-5 py-6 text-center text-sm text-ink-400">Nada enviado ainda.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full {{ $technical ? 'min-w-[44rem]' : 'min-w-[36rem]' }} text-left text-sm">
                <thead class="bg-ink-950 text-[11px] tracking-widest text-ink-400 uppercase">
                    <tr>
                        <th class="px-5 py-2 font-medium">Quando</th><th class="px-3 py-2 font-medium">Tipo</th><th class="px-3 py-2 font-medium">Conteúdo</th>
                        @if ($technical)<th class="px-3 py-2 font-medium">HTTP</th><th class="px-3 py-2 font-medium">Tempo</th>@endif
                        <th class="px-5 py-2 font-medium">Situação</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deliveries as $delivery)
                        <tr class="border-t border-ink-850">
                            <td class="px-5 py-2.5 whitespace-nowrap text-ink-400">{{ $delivery->created_at->timezone($channel->timezone)->format('d/m H:i') }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap">{{ ucfirst($delivery->kindLabel()) }}</td>
                            <td class="px-3 py-2.5 text-white">{{ $delivery->summary }}</td>
                            @if ($technical)
                                <td class="px-3 py-2.5 font-mono whitespace-nowrap {{ $delivery->response_code >= 200 && $delivery->response_code < 300 ? 'text-emerald-300' : 'text-ink-200' }}">{{ $delivery->response_code ?: '—' }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-ink-400">{{ $delivery->duration_ms !== null ? number_format($delivery->duration_ms, 0, ',', '.').' ms' : '—' }}</td>
                            @endif
                            <td class="px-5 py-2.5 {{ ['sent' => 'text-emerald-300', 'failed' => 'text-red-300', 'skipped' => 'text-ink-400'][$delivery->status] ?? 'text-sky-300' }}">
                                {{ $delivery->statusLabel() }}
                                @if ($delivery->status === 'failed' && $delivery->error)<span class="block text-xs text-ink-400">{{ $delivery->error }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
