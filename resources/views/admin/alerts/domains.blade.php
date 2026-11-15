@extends('layouts.admin')

@section('title', 'Alertas · Domínios')

@php
    $statusStyle = ['done' => 'text-emerald-300', 'partial' => 'text-amber-200', 'blocked' => 'text-red-300', 'failed' => 'text-red-300', 'running' => 'text-sky-300'];
    $statusLabel = ['pending' => 'na fila', 'running' => 'rodando', 'done' => 'concluída', 'partial' => 'parcial', 'failed' => 'falhou', 'blocked' => 'bloqueada'];
    $levelStyle = ['ok' => 'text-emerald-300', 'error' => 'text-red-300', 'info' => 'text-ink-200'];
    $tz = 'America/Sao_Paulo';
@endphp

@section('content')
    @include('admin.alerts._nav', ['subtitle' => "{$counts['total']} domínios · {$counts['verified']} verificados · {$counts['waiting']} aguardando o TXT · {$counts['blocked']} bloqueado(s)"])

    <div class="overflow-x-auto rounded-lg border border-ink-800">
        <table class="w-full min-w-[62rem] text-left text-sm">
            <thead class="bg-ink-900 text-[11px] tracking-widest text-ink-400 uppercase">
                <tr>
                    <th class="px-3 py-2 font-medium">Domínio</th>
                    <th class="px-3 py-2 font-medium">Posse</th>
                    <th class="px-3 py-2 font-medium">Aceite</th>
                    <th class="px-3 py-2 font-medium">Última / próxima</th>
                    <th class="px-3 py-2 font-medium">Situação</th>
                    <th class="px-3 py-2 font-medium">Achados</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-800">
                @forelse ($domains as $domain)
                    @php
                        $f = $domain->findings;
                        $user = $domain->profile->user;
                    @endphp
                    <tr class="{{ $log?->id === $domain->id ? 'bg-accent/5' : '' }}">
                        <td class="px-3 py-2.5">
                            <span class="block font-mono text-white">{{ $domain->domain }}</span>
                            <a href="{{ route('admin.alerts.client', $user) }}" class="text-xs text-ink-400 hover:text-ink-200">{{ $domain->profile->company_name ?: $user->name }}</a>
                        </td>
                        <td class="px-3 py-2.5 {{ $domain->verified_at ? 'text-emerald-300' : 'text-amber-200' }}">
                            {{ $domain->verified_at ? 'verificado '.$domain->verified_at->timezone($tz)->format('d/m') : ($domain->consent_at ? 'aguardando TXT desde '.$domain->consent_at->timezone($tz)->format('d/m H:i') : 'sem aceite') }}
                        </td>
                        <td class="px-3 py-2.5 text-ink-400">{{ $domain->consent_at ? $domain->consent_version.' · '.$domain->consent_at->timezone($tz)->format('d/m') : '—' }}</td>
                        <td class="px-3 py-2.5 whitespace-nowrap text-ink-400">
                            {{ $domain->last_run_at?->timezone($tz)->format('d/m H:i') ?? '—' }} · {{ $domain->next_run_at?->timezone($tz)->format('d/m') ?? '—' }}
                        </td>
                        <td class="px-3 py-2.5 {{ $statusStyle[$domain->discovery_status] ?? 'text-ink-400' }}">
                            {{ $statusLabel[$domain->discovery_status] ?? $domain->discovery_status }}
                            @if ($domain->last_error)<span class="block text-xs text-ink-400">{{ $domain->last_error }}</span>@endif
                        </td>
                        <td class="px-3 py-2.5">
                            @if ($f->isNotEmpty())
                                {{ $f->count() }} · {{ $f->where('status', 'accepted')->count() }} no perfil{{ ($removed = $f->where('status', 'ignored')->count()) ? ', '.$removed.' removido(s) pelo cliente' : '' }}
                            @else — @endif
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            @if ($domain->canDiscover() && ($next = $domain->nextScanAt()))
                                <span class="text-xs text-ink-400" title="No máximo 1 varredura por mês por domínio">liberado em {{ $next->timezone($tz)->format('d/m') }}</span>
                            @elseif ($domain->canDiscover())
                                <form method="POST" action="{{ route('admin.alerts.domains.run', $domain) }}" class="inline">@csrf
                                    <button class="rounded-md border border-ink-800 px-2 py-1 text-xs text-ink-200 hover:border-ink-700">Rodar agora</button>
                                </form>
                            @endif
                            <a href="{{ route('admin.alerts.domains', ['log' => $domain->id]) }}" class="ml-2 text-xs text-accent hover:underline">Log</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-8 text-center text-ink-400">Nenhum domínio cadastrado ainda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $domains->links() }}</div>

    @if ($log)
        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <section class="overflow-hidden rounded-lg border {{ $log->discovery_status === 'blocked' ? 'border-red-900' : 'border-ink-800' }} bg-ink-900">
                <div class="flex items-baseline justify-between border-b border-ink-800 px-4 py-3">
                    <h2 class="text-sm font-semibold text-white">Última execução · <span class="font-mono">{{ $log->domain }}</span></h2>
                    <span class="text-xs {{ $statusStyle[$log->discovery_status] ?? 'text-ink-400' }}">{{ $statusLabel[$log->discovery_status] ?? $log->discovery_status }}</span>
                </div>
                @if (empty($log->last_log))
                    <p class="px-4 py-6 text-center text-sm text-ink-400">Ainda não rodou.</p>
                @else
                    <ol class="space-y-1.5 px-4 py-3 font-mono text-xs">
                        @foreach ($log->last_log as $line)
                            <li class="flex gap-3">
                                <span class="shrink-0 text-ink-400">{{ \Illuminate\Support\Carbon::parse($line['t'])->timezone($tz)->format('H:i:s') }}</span>
                                <span class="min-w-0 break-all {{ $levelStyle[$line['level']] ?? 'text-ink-200' }}">{{ $line['msg'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
                @if ($log->discovery_status === 'blocked')
                    <p class="px-4 pb-4 text-xs text-ink-400">O cliente vê “não conseguimos analisar o domínio: o site redireciona para um endereço interno”. Nada é tentado de novo até a próxima varredura mensal.</p>
                @endif
            </section>

            <section class="overflow-hidden rounded-lg border border-ink-800 bg-ink-900">
                <h2 class="border-b border-ink-800 px-4 py-3 text-sm font-semibold text-white">Achados</h2>
                @forelse ($log->findings->sortByDesc('confidence') as $finding)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-ink-850 px-4 py-2.5 text-sm first:border-t-0">
                        <span class="min-w-32 font-semibold text-white">{{ $finding->label }}</span>
                        <code class="min-w-0 flex-1 font-mono text-xs break-all text-ink-200">{{ $finding->evidence }}</code>
                        <span class="text-xs text-ink-400">{{ $finding->confidenceLabel() }} · {{ ['suggested' => 'pendente', 'accepted' => 'no perfil', 'ignored' => 'removido pelo cliente'][$finding->status] ?? $finding->status }}</span>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-ink-400">Nenhum achado.</p>
                @endforelse
                @foreach ($log->hints ?? [] as $hint)
                    <p class="border-t border-ink-850 px-4 py-3 text-xs text-amber-200">{{ is_array($hint) ? 'Aviso ao cliente' : 'Dica ao cliente' }}: {{ is_array($hint) ? $hint['texto'] : $hint }}</p>
                @endforeach
            </section>
        </div>
    @endif
@endsection
