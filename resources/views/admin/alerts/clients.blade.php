@extends('layouts.admin')

@section('title', 'Alertas · Clientes')

@section('content')
    @include('admin.alerts._nav', ['subtitle' => "{$counts['clients']} clientes · {$counts['active']} ativos · {$counts['drafts']} em rascunho · {$counts['paused']} pausado(s)"])

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nome, e-mail ou empresa" aria-label="Buscar cliente"
               class="min-w-0 flex-1 rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm">
        <select name="perfil" aria-label="Perfil" class="rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm [color-scheme:dark]">
            <option value="">Perfil: todos</option>
            @foreach (['active' => 'ativo', 'draft' => 'rascunho', 'paused' => 'pausado', 'none' => 'sem perfil'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['perfil'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="canal" aria-label="Canal" class="rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm [color-scheme:dark]">
            <option value="">Canal: todos</option>
            @foreach (['ok' => 'ok', 'broken' => 'com problema', 'none' => 'sem canal'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['canal'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-ink-700">Filtrar</button>
    </form>

    <div class="overflow-x-auto rounded-lg border border-ink-800">
        <table class="w-full min-w-[60rem] text-left text-sm">
            <thead class="bg-ink-900 text-[11px] tracking-widest text-ink-400 uppercase">
                <tr>
                    <th class="px-3 py-2 font-medium">Cliente</th>
                    <th class="px-3 py-2 font-medium">Perfil</th>
                    <th class="px-3 py-2 font-medium">Nível</th>
                    <th class="px-3 py-2 text-right font-medium">Tecnol.</th>
                    <th class="px-3 py-2 font-medium">Canais</th>
                    <th class="px-3 py-2 font-medium">Modos</th>
                    <th class="px-3 py-2 text-right font-medium">Alertas 30 d</th>
                    <th class="px-3 py-2 text-right font-medium">Não relev.</th>
                    <th class="px-3 py-2 font-medium">Último envio</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-800">
                @forelse ($users as $user)
                    @php
                        $p = $user->alertProfile;
                        [$pLabel, $pClass] = match (true) {
                            ! $p => ['sem perfil', 'border-ink-800 text-ink-400'],
                            ! $p->completed_at => ['rascunho · passo '.$p->step_reached, 'border-ink-700 text-ink-400'],
                            $p->isPaused() => ['pausado', 'border-accent/40 text-amber-200'],
                            default => ['ativo', 'border-emerald-800 text-emerald-300'],
                        };
                        $rate = $p && $p->alerts_30d ? round($p->irrelevant_30d / $p->alerts_30d * 100) : null;
                    @endphp
                    <tr>
                        <td class="px-3 py-2.5">
                            <span class="block font-semibold text-white">{{ $p?->company_name ?: $user->name }}</span>
                            <span class="text-xs text-ink-400">{{ $user->name }} · {{ $user->email }}</span>
                        </td>
                        <td class="px-3 py-2.5">
                            <span class="rounded-full border px-2 py-0.5 text-xs whitespace-nowrap {{ $pClass }}">{{ $pLabel }}</span>
                            @if ($user->alert_profiles_count > 1)
                                <span class="mt-1 block text-xs text-ink-400">{{ $user->alert_profiles_count }} alertas</span>
                            @endif
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">{{ $p?->completed_at ? $p->levelLabel() : '—' }}</td>
                        <td class="px-3 py-2.5 text-right">{{ $p?->techs_count ?? '—' }}</td>
                        <td class="px-3 py-2.5">
                            @forelse ($user->notificationChannels as $c)
                                <span class="block {{ $c->status === 'active' ? 'text-emerald-300' : ($c->status === 'pending' ? 'text-amber-200' : 'text-red-300') }}">
                                    {{ $c->appName() }} · {{ ['active' => 'ok', 'pending' => 'falta o canal'][$c->status] ?? 'com problema' }}
                                </span>
                                <span class="text-xs text-ink-400">{{ $c->label() }}</span>
                            @empty
                                <span class="text-ink-400">sem canal</span>
                            @endforelse
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">
                            {{ $user->notificationChannels->map(fn ($c) => collect([$c->realtime ? 'na hora' : null, $c->digest ? $c->digest_time : null])->filter()->implode(' + ') ?: 'nenhum')->unique()->implode(' / ') ?: '—' }}
                        </td>
                        <td class="px-3 py-2.5 text-right">{{ $p?->alerts_30d ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-right {{ ($rate ?? 0) >= 20 ? 'text-orange-300' : '' }}">{{ $rate === null ? '—' : $rate.'%' }}</td>
                        <td class="px-3 py-2.5 whitespace-nowrap text-ink-400">
                            {{ isset($lastSent[$user->id]) ? \Illuminate\Support\Carbon::parse($lastSent[$user->id])->timezone('America/Sao_Paulo')->format('d/m H:i') : '—' }}
                        </td>
                        <td class="px-3 py-2.5 text-right"><a href="{{ route('admin.alerts.client', $user) }}" class="text-accent hover:underline">Abrir</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-8 text-center text-ink-400">Nenhum cliente com esses filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
@endsection
