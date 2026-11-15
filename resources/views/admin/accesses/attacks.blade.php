@extends('layouts.admin')

@section('title', 'Acessos · Ataques')

@section('content')
    @php
        $num = fn ($value) => number_format((int) $value, 0, ',', '.');
        $category = fn (?string $code) => $code ? \App\Support\AttackSignatures::label($code) : 'só recusado';
        $ipLink = fn (string $ip) => route('admin.accesses.ip', ['ip' => $ip]);
        $badge = 'ml-1 rounded px-1 text-[10px] [word-break:normal]';
        $defaults = ['periodo' => '24h', 'nossos' => 'esconder'];
        $filter = fn (array $change) => route('admin.accesses.attacks', array_filter(array_merge($filters, $change), fn ($v, $k) => $v !== '' && $v !== null && ($defaults[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        $sweep = \App\Services\Admin\AttackReport::SWEEP;
        // Os quadros pequenos: título, colunas (campo => nome) e linhas; "Mesma conta" só aparece quando tem linha. A
        // categoria vira filtro da aba (a sem categoria é "recusado").
        $tables = array_filter([
            ['Por categoria', ['category' => 'Categoria', 'events' => 'Eventos', 'ips' => 'IPs'], $byCategory],
            ['Por método e resposta', ['method' => 'Método', 'band' => 'Resposta', 'events' => 'Eventos'], $byStatus],
            $accounts->isNotEmpty() ? ['Mesma conta tentada de 2 IPs ou mais', ['account_hmac' => 'Código da conta', 'ips' => 'IPs', 'refusals' => 'Recusas'], $accounts] : null,
        ]);
        $numeric = ['events', 'ips', 'paths', 'refusals', 'dropped'];
        $ips = $mais === 'ips' ? $byIp : $byIp->take(15);
        $h2 = 'mb-3 text-xs font-semibold tracking-widest text-ink-700 uppercase';
    @endphp

    @include('admin.accesses._tabs', ['route' => 'admin.accesses.attacks'])
    @include('admin.accesses._filters', ['route' => 'admin.accesses.attacks', 'fields' => ['categoria', 'nossos', 'ip', 'pais']])

    <details class="-mt-3 mb-6 max-w-3xl text-xs text-ink-700">
        <summary class="cursor-pointer">O que o site recusou ou teve cara de ataque: <span class="text-ink-400 hover:text-ink-200">o que entra e o que não entra aqui</span></summary>
        <p class="mt-1 leading-relaxed">
            Entram as respostas 4xx e 5xx de qualquer método, os métodos incomuns, o /admin aberto por quem não é admin, as
            tentativas recusadas de login e cadastro e o que tem cara de ataque, mesmo quando a resposta foi 200. É o que a requisição
            tentou, não se deu certo. O que a Cloudflare, a borda e o nginx barram antes do PHP (*.php, arquivos com ponto, 403/429 da
            busca, 444) não aparece aqui. Os nossos ficam escondidos por padrão e nunca contam como atacante.
        </p>
    </details>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        @foreach ($tables as [$title, $columns, $rows])
            <section class="min-w-0">
                <h2 class="{{ $h2 }}">{{ $title }}</h2>
                <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
                    <table class="w-full text-left text-sm">
                        <tbody class="divide-y divide-ink-800">
                            <tr class="text-[11px] tracking-widest text-ink-700 uppercase">
                                @foreach ($columns as $field => $name)<th @class(['px-4 py-2 font-medium', 'text-right' => in_array($field, $numeric, true)])>{{ $name }}</th>@endforeach
                            </tr>
                            @forelse ($rows as $row)
                                <tr>
                                    @foreach ($columns as $field => $name)
                                        @if (in_array($field, $numeric, true))
                                            <td class="px-4 py-2 text-right font-mono text-ink-200">{{ $num($row->{$field}) }}</td>
                                        @elseif ($field === 'ip')
                                            <td class="px-4 py-2 font-mono text-xs break-all"><a href="{{ $ipLink($row->ip) }}" class="text-ink-200 hover:text-accent">{{ $row->ip }}</a></td>
                                        @elseif ($field === 'category')
                                            <td class="px-4 py-2 text-xs"><a href="{{ $filter(['categoria' => $row->category ?? 'recusado']) }}" class="text-ink-200 hover:text-accent">{{ $category($row->category) }}</a></td>
                                        @else
                                            <td class="px-4 py-2 font-mono text-xs break-all text-ink-200">{{ $field === 'account_hmac' ? substr($row->{$field}, 0, 8) : $row->{$field} }}</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($columns) }}" class="px-4 py-4 text-sm text-ink-400">Nada nesse recorte.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
        {{-- O que passou do teto por minuto (só contado, não gravado): o total no título, as horas ao abrir. --}}
        @if ($dropped->isNotEmpty())
            <details class="min-w-0 rounded-lg border border-ink-800 bg-ink-900 px-4 py-3 text-xs">
                <summary class="cursor-pointer text-ink-400">Descartados acima do teto: <span class="font-mono text-ink-200">{{ $num($dropped->sum('dropped')) }}</span></summary>
                <ul class="mt-2 space-y-0.5 font-mono">
                    @foreach ($dropped as $row)
                        <li class="flex justify-between gap-4"><span class="text-ink-400">{{ $row->hour }}</span><span class="text-ink-200">{{ $num($row->dropped) }}</span></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>

    <section id="ips" class="mb-8">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-xs font-semibold tracking-widest text-ink-700 uppercase">IPs com mais eventos</h2>
            <a href="{{ $filter(['varredura' => $filters['varredura'] === '1' ? null : '1']) }}" class="text-xs text-ink-400 hover:text-accent"
               title="Os IPs que pediram {{ $sweep }} endereços ou mais com 4xx">{{ $filters['varredura'] === '1' ? 'Todos os IPs' : 'Só varredura ›' }}</a>
        </div>
        <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                    <tr>
                        <th class="px-4 py-2 font-medium">IP</th>
                        <th class="px-4 py-2 text-right font-medium">Eventos</th>
                        <th class="hidden px-4 py-2 text-right font-medium sm:table-cell">Endereços 4xx</th>
                        <th class="hidden px-4 py-2 text-right font-medium sm:table-cell">Categorias</th>
                        <th class="px-4 py-2 text-right font-medium">Recusas de login</th>
                        <th class="px-4 py-2 text-right font-medium" title="IPs que dividem com este um código de aparelho não comum">Aparelho em outros IPs</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-800">
                    @forelse ($ips as $row)
                        <tr>
                            <td class="min-w-32 px-4 py-2 font-mono text-xs break-all">
                                <a href="{{ $ipLink($row->ip) }}" class="text-ink-200 hover:text-accent" title="{{ $row->user_agent }}">{{ $row->ip }}</a>
                                <span class="text-ink-700">· {{ $row->country ?? '—' }}</span>
                                @if ($row->paths >= $sweep)<span class="{{ $badge }} bg-amber-950/60 text-amber-300" title="{{ $num($row->paths) }} endereços com 4xx">varredura</span>@endif
                                @if ($row->ours)<span class="{{ $badge }} bg-sky-950/60 text-sky-300">nosso</span>
                                @elseif ($row->scanner)<span class="{{ $badge }} bg-amber-950/60 text-amber-300">ferramenta de varredura</span>
                                @elseif ($row->attacker)<span class="{{ $badge }} bg-red-950/50 text-red-300">atacante</span>
                                @elseif ($row->robot_kind)<span class="{{ $badge }} bg-amber-950/60 text-amber-300">{{ \App\Support\RobotCatalog::label($row->robot_kind) }}</span>@endif
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-ink-200">{{ $num($row->events) }}</td>
                            <td class="hidden px-4 py-2 text-right font-mono text-ink-400 sm:table-cell">{{ $num($row->paths) }}</td>
                            <td class="hidden px-4 py-2 text-right font-mono text-ink-400 sm:table-cell">{{ $num($row->categories) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-ink-400">{{ $num($row->refusals) }}@if ($row->accounts) <span class="text-[11px] text-ink-700">({{ $num($row->accounts) }} conta(s))</span>@endif</td>
                            <td class="px-4 py-2 text-right font-mono text-xs text-ink-400">
                                @if ($device = $deviceIps[$row->ip] ?? null){{ $num($device->others) }} <span class="text-[10px] text-ink-700">{{ $device->confidence }}</span>@else — @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-4 text-sm text-ink-400">Nenhum evento nesse recorte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($mais === 'ips')
            <a href="{{ $filter(['mais' => null]) }}#ips" class="mt-2 inline-block text-xs text-ink-400 hover:text-accent">Ver menos</a>
        @elseif ($byIp->count() > 15)
            <a href="{{ $filter(['mais' => 'ips']) }}#ips" class="mt-2 inline-block text-xs text-ink-400 hover:text-accent">Ver os {{ $byIp->count() }} IPs ›</a>
        @endif
    </section>

    {{-- Códigos raros vistos em 2 ou mais dos IPs acima (sem os nossos): o mesmo aparelho trocando de IP. Nunca a conta. Só com linha. --}}
    @if ($moved->isNotEmpty())
    <section class="mb-8">
        <h2 class="mb-3 text-xs font-semibold tracking-widest text-ink-700 uppercase">Aparelhos que trocaram de IP</h2>
        <div class="rounded-lg border border-ink-800 bg-ink-900 p-3 text-xs">
            @foreach ($moved as $code)
                <p class="py-1">
                    <code class="font-mono text-ink-200">{{ substr($code->fingerprint, 0, 8) }}</code>
                    <span class="text-ink-700">· {{ $code->confidence }} · em</span>
                    @foreach ($code->among as $ip)<a href="{{ $ipLink($ip) }}" class="font-mono text-ink-200 hover:text-accent">{{ $ip }}</a>{{ $loop->last ? '' : ', ' }}@endforeach
                </p>
            @endforeach
        </div>
    </section>
    @endif

    <section>
        <h2 class="mb-3 text-xs font-semibold tracking-widest text-ink-700 uppercase">Últimos eventos</h2>
        @include('admin.accesses._events', ['rows' => $events])
    </section>
@endsection
