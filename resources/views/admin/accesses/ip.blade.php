@extends('layouts.admin')

@section('title', 'Acessos · '.$filters['ip'])

@section('content')
    @php
        $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $when = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—';
        $num = fn ($value) => number_format((int) $value, 0, ',', '.');
        $first = collect([$views->first_at, $events->first_at])->filter()->min();
        $last = collect([$views->last_at, $events->last_at])->filter()->max();
        $userAgents = json_decode((string) $views->user_agents, true) ?: [];
        // O diálogo de "Apagar tudo": quantos acessos, eventos e códigos de aparelho o IP tem guardados (o período inteiro).
        $plural = fn (int $n, string $one, string $many) => $n === 1 ? '1 '.$one : $num($n).' '.$many;
        $records = $plural($stored['views'], 'acesso', 'acessos').($stored['devices'] ? ', ' : ' e ').$plural($stored['events'], 'evento', 'eventos')
            .($stored['devices'] ? ' e '.$plural($stored['devices'], 'código de aparelho', 'códigos de aparelho') : '');
        $ip = $filters['ip'];
    @endphp

    @include('admin.accesses._tabs', ['route' => 'admin.accesses.ip'])

    <form method="GET" action="{{ route('admin.accesses.ip') }}" class="mb-6 grid gap-3 sm:grid-cols-3 sm:items-end">
        <label class="block"><span class="{{ $label }}">IP</span>
            <input name="ip" value="{{ $filters['ip'] }}" maxlength="45" class="{{ $input }} font-mono">
            @error('ip')<span class="mt-1 block text-xs text-red-300">{{ $message }}</span>@enderror
        </label>
        <label class="block"><span class="{{ $label }}">Período</span>
            <select name="periodo" class="{{ $input }}">
                @foreach (['24h' => 'Últimas 24 h', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', '180d' => 'Tudo o que está guardado (180 dias)'] as $value => $name)
                    <option value="{{ $value }}" @selected($filters['periodo'] === $value)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <div>
            <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">Abrir</button>
        </div>
    </form>

    <section class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['Páginas abertas', $num($views->total), $num($views->timed).' com o tempo da página'],
            ['Eventos', $num($events->total), $events->categories ?: 'sem categoria'],
            ['Recusas de login e cadastro', $num($events->refusals), $num($events->accounts).' conta(s) tentada(s)'],
            ['Primeira e última vez', $when($first), $when($last)],
        ] as [$name, $value, $note])
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-3">
                <p class="mb-0.5 text-[11px] text-ink-700">{{ $name }}</p>
                <p class="font-mono text-lg text-white">{{ $value }}</p>
                <p class="text-[11px] break-words text-ink-700">{{ $note }}</p>
            </div>
        @endforeach
    </section>

    <section class="mb-8 text-xs text-ink-400">
        <p><span class="text-ink-700">Países:</span> {{ $views->countries ?? '—' }} @if ($views->accounts)<span class="text-ink-700">· {{ $num($views->accounts) }} conta(s) logada(s)</span>@endif</p>
        @if ($userAgents)
            <details class="mt-1">
                <summary class="cursor-pointer text-ink-700 hover:text-ink-400">Navegadores ({{ count($userAgents) }}{{ count($userAgents) === 10 ? ', até 10' : '' }})</summary>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($userAgents as $userAgent)
                        <li><code class="font-mono break-all">{{ $userAgent }}</code></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </section>

    @include('admin.accesses._device')

    <section>
        <h2 class="mb-3 text-xs font-semibold tracking-widest text-ink-700 uppercase">Tudo o que este IP fez</h2>
        @include('admin.accesses._events', ['rows' => $timeline, 'showIp' => false, 'erasable' => true])
    </section>

    {{-- A zona de perigo: o que muda ou some de vez. Os dois botões perguntam antes (data-confirm). --}}
    <section class="mt-10 rounded-lg border border-red-900/60 p-4" aria-labelledby="perigo">
        <h2 id="perigo" class="mb-3 text-xs font-semibold tracking-widest text-red-300 uppercase">Zona de perigo</h2>
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="max-w-xl text-xs leading-relaxed text-ink-400">
                    <span class="block text-sm text-ink-200">Contar como nosso</span>
                    Entra na lista de IPs nossos (Ajustes): os acessos dele, de agora e de antes, ganham o selo "nosso" e ficam escondidos por padrão. Nada é apagado.
                </p>
                @if ($ours)
                    <span class="text-xs text-ink-400">{{ $ip }} já conta como nosso.</span>
                @else
                    <form method="POST" action="{{ route('admin.accesses.own.add') }}"
                          data-confirm="Contar o IP {{ $ip }} como nosso? Os acessos dele passam a ficar escondidos por padrão. Dá para tirar depois em Ajustes." data-confirm-ok="Contar como nosso">
                        @csrf
                        <input type="hidden" name="endereco" value="{{ $ip }}">
                        <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">Contar como nosso</button>
                    </form>
                @endif
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-red-900/40 pt-4">
                <p class="max-w-xl text-xs leading-relaxed text-ink-400">
                    <span class="block text-sm text-ink-200">Apagar tudo deste IP</span>
                    Saem {{ $records }}, de todo o período guardado (não só do período escolhido acima).
                </p>
                <form method="POST" action="{{ route('admin.accesses.destroy-ip') }}" data-confirm-ok="Apagar tudo"
                      data-confirm="Apagar tudo do IP {{ $ip }}? Saem {{ $records }}, de todo o período guardado (não só deste filtro). Não dá para desfazer.">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="endereco" value="{{ $ip }}">
                    <button class="rounded-lg border border-red-900/60 px-4 py-2 text-sm text-red-300 hover:bg-red-950/50">Apagar tudo deste IP</button>
                </form>
            </div>
        </div>
    </section>
@endsection
