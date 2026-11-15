@extends('layouts.admin')

@section('title', 'Alertas · Entregas')

@php
    $statusStyle = ['sent' => 'text-emerald-300', 'failed' => 'text-red-300', 'queued' => 'text-sky-300', 'skipped' => 'text-ink-400'];
@endphp

@section('content')
    @include('admin.alerts._nav', ['subtitle' => 'No período: '.($totals['sent'] ?? 0).' entregues · '.($totals['failed'] ?? 0).' falhas · '.($totals['queued'] ?? 0).' na fila · '.($totals['skipped'] ?? 0).' puladas'])

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        @foreach ([
            'status' => ['' => 'Situação: todas', 'sent' => 'entregues', 'failed' => 'falhas', 'queued' => 'na fila', 'skipped' => 'puladas'],
            'tipo' => ['' => 'Tipo: todos', 'realtime' => 'na hora', 'grouped' => 'agrupado', 'digest' => 'resumo', 'test' => 'teste', 'code' => 'código', 'tests' => 'aviso dos testes'],
            'periodo' => ['24h' => 'Últimas 24 h', '7d' => '7 dias', '30d' => '30 dias'],
        ] as $name => $options)
            <select name="{{ $name }}" aria-label="{{ $name }}" class="rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm [color-scheme:dark]">
                @foreach ($options as $value => $label)
                    <option value="{{ $value }}" @selected(($filters[$name] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        @endforeach
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cliente" aria-label="Cliente"
               class="min-w-0 flex-1 rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm">
        <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-ink-700">Filtrar</button>
    </form>

    @if ($payload)
        <section class="mb-4 rounded-lg border border-ink-800 bg-ink-900 p-4">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-white">Payload do envio #{{ $payload->id }} · {{ $payload->kindLabel() }}</h2>
                <a href="{{ request()->fullUrlWithoutQuery('payload') }}" class="text-xs text-ink-400 hover:text-ink-200">fechar</a>
            </div>
            <pre class="max-h-96 overflow-auto rounded-lg border border-ink-800 bg-ink-950 p-3 font-mono text-xs text-ink-200">{{ json_encode($payload->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>
    @endif

    <div class="overflow-x-auto rounded-lg border border-ink-800">
        <table class="w-full min-w-[62rem] text-left text-sm">
            <thead class="bg-ink-900 text-[11px] tracking-widest text-ink-400 uppercase">
                <tr>
                    <th class="px-3 py-2 font-medium">Quando</th>
                    <th class="px-3 py-2 font-medium">Cliente · canal</th>
                    <th class="px-3 py-2 font-medium">Tipo</th>
                    <th class="px-3 py-2 font-medium">Conteúdo</th>
                    <th class="px-3 py-2 font-medium">Situação</th>
                    <th class="px-3 py-2 text-right font-medium">Tent.</th>
                    <th class="px-3 py-2 font-medium">Resposta</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-800">
                @forelse ($deliveries as $d)
                    <tr>
                        <td class="px-3 py-2.5 font-mono text-xs whitespace-nowrap text-ink-400">{{ $d->created_at->timezone('America/Sao_Paulo')->format('d/m H:i:s') }}</td>
                        <td class="px-3 py-2.5">
                            <a href="{{ route('admin.alerts.client', $d->user) }}" class="block text-white hover:text-accent">{{ $d->user->alertProfile?->company_name ?: $d->user->name }}</a>
                            <span class="text-xs text-ink-400">{{ $d->channel->appName() }} · {{ $d->channel->label() }} · {{ $d->channel->methodLabel() }}</span>
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">{{ $d->kindLabel() }}</td>
                        <td class="px-3 py-2.5 text-white">{{ $d->summary }}</td>
                        <td class="px-3 py-2.5 whitespace-nowrap {{ $statusStyle[$d->status] ?? '' }}">{{ $d->statusLabel() }}</td>
                        <td class="px-3 py-2.5 text-right">{{ $d->attempts }}</td>
                        <td class="px-3 py-2.5 text-xs text-ink-400">{{ $d->response_code ? $d->response_code.' ' : '' }}{{ $d->error }}</td>
                        <td class="px-3 py-2.5 text-right whitespace-nowrap">
                            @if ($d->status === 'failed' && $d->kind !== 'code')
                                <form method="POST" action="{{ route('admin.alerts.deliveries.retry', $d) }}" class="inline">@csrf
                                    <button class="rounded-md border border-accent px-2 py-1 text-xs text-amber-200 hover:bg-accent/10">Reenviar</button>
                                </form>
                            @endif
                            <a href="{{ request()->fullUrlWithQuery(['payload' => $d->id]) }}" class="ml-2 text-xs text-accent hover:underline">payload</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-ink-400">Nenhum envio nesse período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-ink-400">Canal com 404/410 não tem reenvio: o cliente precisa reconectar primeiro. O teste sempre pode ser reenviado. O código de confirmação nunca: o histórico guarda só pontos no lugar dele.</p>
    <div class="mt-4">{{ $deliveries->links() }}</div>
@endsection
