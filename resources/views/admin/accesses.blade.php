@extends('layouts.admin')

@section('title', 'Acessos')

@section('content')
    @php
        $when = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->timezone('America/Sao_Paulo')->format('d/m H:i:s') : '—';
        $num = fn ($value) => number_format((int) $value, 0, ',', '.');
        $time = function ($ms) {
            if ($ms === null) {
                return '—';
            }
            $seconds = (int) round($ms / 1000);

            return $seconds < 60 ? $seconds.' s' : intdiv($seconds, 60).' min '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).' s';
        };
        $defaults = ['periodo' => '24h', 'robos' => 'com', 'nossos' => 'esconder'];
        $filter = fn (array $change) => route('admin.accesses', array_filter(array_merge($filters, $change), fn ($v, $k) => $v !== '' && $v !== null && ($defaults[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        $hiddenOurs = (int) $totals->hidden_ours;
        // Os cartões levam às outras abas com os filtros comuns (os mesmos de _tabs), sem os padrões.
        $common = array_filter(\Illuminate\Support\Arr::only($filters, ['periodo', 'pais', 'ip', 'nossos']), fn ($v, $k) => $v !== '' && ($defaults[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        // "Ver mais" sem JS: ?mais=paginas|ips|paises mostra a lista inteira da seção (30, 50, 20); sem ele, as primeiras.
        $more = fn (?string $what, string $anchor) => $filter(['mais' => $what]).'#'.$anchor;
        $pages = $mais === 'paginas' ? $byPage : $byPage->take(10);
        $ips = $mais === 'ips' ? $byIp : $byIp->take(10);
        $countries = $mais === 'paises' ? $byCountry : $byCountry->take(8);
        $h2 = 'text-xs font-semibold tracking-widest text-ink-700 uppercase';
        $th = 'px-4 py-2 font-medium';
        $seeMore = 'mt-2 inline-block text-xs text-ink-400 hover:text-accent';
    @endphp

    @include('admin.accesses._tabs', ['route' => 'admin.accesses'])

    @include('admin.accesses._filters', ['route' => 'admin.accesses', 'fields' => ['ip', 'pais', 'pagina', 'nossos'], 'quem' => true, 'hiddenOurs' => $hiddenOurs])

    {{-- Páginas abertas sem os redirecionamentos (o /idioma); pessoas: os IPs que ficaram 1 s ou mais numa página (AccessReport::person). --}}
    <section class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Páginas abertas', $num($totals->views), $num($totals->ips).' IPs · '.$num($totals->countries).' país(es)'.($totals->redirects ? ' · fora '.$num($totals->redirects).' redirecionamento(s)' : ''), null, null],
            // Pessoas e Robôs na mesma unidade de "Páginas abertas" (sem os 3xx): os dois somam o total; os IPs vão na nota.
            ['Pessoas', $num($totals->people_views), $num($totals->people).' IP(s) · '.$num($totals->accounts).' conta(s) logada(s) · tempo médio '.$time($totals->avg_ms), null,
                'IPs que ficaram '.(\App\Services\Admin\AccessReport::HUMAN_MIN_MS / 1000).' s ou mais numa página'],
            ['Robôs', $num($totals->bots), $num($totals->bot_ips).' IP(s) · declarados: '.$num($totals->declared), route('admin.accesses.robots', $common), 'Tudo o que não é pessoa'],
            ['Erros', $num($totals->errors), 'respostas 4xx/5xx', route('admin.accesses.attacks', $common), 'O que o site recusou: a aba Ataques'],
        ] as [$name, $value, $note, $href, $about])
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-3" @if ($about) title="{{ $about }}" @endif>
                @if ($href)
                    <a href="{{ $href }}" class="mb-0.5 block text-[11px] text-ink-400 hover:text-accent">{{ $name }} ›</a>
                @else
                    <p class="mb-0.5 text-[11px] text-ink-700">{{ $name }}</p>
                @endif
                <p class="font-mono text-lg text-white">{{ $value }}</p>
                <p class="text-[11px] text-ink-700">{{ $note }}</p>
            </div>
        @endforeach
    </section>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <section id="paginas" class="min-w-0">
            <h2 class="mb-3 {{ $h2 }}">Páginas mais abertas</h2>
            <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                        <tr>
                            <th class="{{ $th }}">Página</th>
                            <th class="{{ $th }} text-right">Acessos</th>
                            <th class="{{ $th }} text-right">IPs</th>
                            <th class="hidden {{ $th }} text-right sm:table-cell">Tempo médio</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-800">
                        @forelse ($pages as $row)
                            <tr>
                                {{-- max-w-0 + w-full: a coluna da página fica com a sobra e o caminho longo é cortado (inteiro no title). --}}
                                <td class="w-full max-w-0 px-4 py-2 font-mono text-xs">
                                    <a href="{{ $filter(['pagina' => $row->page]) }}" class="block truncate text-ink-200 hover:text-accent" title="{{ $row->page }}">{{ $row->page }}</a>
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-ink-200">{{ $num($row->views) }}</td>
                                <td class="px-4 py-2 text-right font-mono text-ink-400">{{ $num($row->ips) }}</td>
                                <td class="hidden px-4 py-2 text-right font-mono text-xs whitespace-nowrap text-ink-400 sm:table-cell">{{ $time($row->avg_ms) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-4 text-sm text-ink-400">Nenhuma página nesse recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($mais === 'paginas')
                <a href="{{ $more(null, 'paginas') }}" class="{{ $seeMore }}">Ver menos</a>
            @elseif ($byPage->count() > 10)
                <a href="{{ $more('paginas', 'paginas') }}" class="{{ $seeMore }}">Ver as {{ $byPage->count() }} páginas ›</a>
            @endif
        </section>

        <section id="ips" class="min-w-0">
            <h2 class="mb-3 {{ $h2 }}">Quem mais acessou</h2>
            <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                        <tr>
                            <th class="{{ $th }}">IP</th>
                            <th class="{{ $th }}">País</th>
                            <th class="{{ $th }} text-right">Acessos</th>
                            <th class="hidden {{ $th }} sm:table-cell">Último</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-800">
                        @forelse ($ips as $row)
                            <tr>
                                <td class="min-w-32 px-4 py-2 font-mono text-xs break-all">
                                    <a href="{{ route('admin.accesses.ip', ['ip' => $row->ip]) }}" class="text-ink-200 hover:text-accent" title="{{ $row->user_agent }}">{{ $row->ip }}</a>
                                    @include('admin.accesses._visitor', ['row' => $row])
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">
                                    @if ($row->country)<a href="{{ $filter(['pais' => $row->country]) }}" class="text-ink-400 hover:text-accent">{{ $row->country }}</a>@else<span class="text-ink-700">—</span>@endif
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-ink-200">{{ $num($row->views) }}</td>
                                <td class="hidden px-4 py-2 text-xs whitespace-nowrap text-ink-700 sm:table-cell">{{ $when($row->last_at) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-4 text-sm text-ink-400">Nenhum acesso nesse recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($mais === 'ips')
                <a href="{{ $more(null, 'ips') }}" class="{{ $seeMore }}">Ver menos</a>
            @elseif ($byIp->count() > 10)
                <a href="{{ $more('ips', 'ips') }}" class="{{ $seeMore }}">Ver os {{ $byIp->count() }} IPs ›</a>
            @endif
        </section>
    </div>

    <section class="mb-8">
        <h2 class="mb-3 {{ $h2 }}">{{ $filters['periodo'] === '24h' ? 'Por hora' : 'Por dia' }} (horário de Brasília)</h2>
        <x-admin.bars :rows="$series" :legend="['people' => 'pessoas', 'robots' => 'robôs']" />
    </section>

    {{-- Países em chips: o clique filtra; os IPs vão no title. --}}
    <section id="paises" class="mb-8">
        <h2 class="mb-3 {{ $h2 }}">Países</h2>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @forelse ($countries as $row)
                @if ($row->country !== '—')
                    <a href="{{ $filter(['pais' => $row->country]) }}" title="{{ $num($row->ips) }} IP(s)"
                       class="rounded-full border border-ink-800 bg-ink-900 px-2.5 py-0.5 font-mono text-ink-200 hover:border-accent">{{ $row->country }} <span class="text-ink-400">{{ $num($row->views) }}</span></a>
                @else
                    <span title="{{ $num($row->ips) }} IP(s)" class="rounded-full border border-ink-800 px-2.5 py-0.5 text-ink-700">sem país <span class="font-mono">{{ $num($row->views) }}</span></span>
                @endif
            @empty
                <span class="text-sm text-ink-400">Nenhum país nesse recorte.</span>
            @endforelse
            @if ($mais === 'paises')
                <a href="{{ $more(null, 'paises') }}" class="px-1 text-ink-400 hover:text-accent">Ver menos</a>
            @elseif ($byCountry->count() > 8)
                <a href="{{ $more('paises', 'paises') }}" class="px-1 text-ink-400 hover:text-accent">+{{ $byCountry->count() - 8 }} ›</a>
            @endif
        </div>
    </section>

    <section>
        <h2 class="mb-3 {{ $h2 }}">Últimos acessos</h2>
        <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                    <tr>
                        <th class="{{ $th }}">Quando</th>
                        <th class="{{ $th }}">Quem</th>
                        <th class="{{ $th }}">Página</th>
                        <th class="{{ $th }} text-right">Resposta</th>
                        <th class="hidden {{ $th }} text-right sm:table-cell">Tempo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-800">
                    @foreach ($recent as $view)
                        <tr>
                            <td class="px-4 py-2 text-xs whitespace-nowrap text-ink-400">{{ $when($view->created_at) }}</td>
                            <td class="min-w-32 px-4 py-2 font-mono text-xs break-all">
                                <a href="{{ route('admin.accesses.ip', ['ip' => $view->ip]) }}" class="text-ink-200 hover:text-accent" title="{{ $view->user_agent }}">{{ $view->ip }}</a>
                                <span class="text-ink-700">· {{ $view->country ?? '—' }}</span>
                                @include('admin.accesses._visitor', ['row' => $view])
                            </td>
                            <td class="w-full max-w-0 px-4 py-2 font-mono text-xs text-ink-200">
                                <span class="block truncate" title="{{ $view->path }}">{{ $view->path }}</span>
                                @if ($view->attack)<span class="mt-0.5 block font-sans" title="assinatura conhecida de ataque ou de varredura: o que a requisição tentou, não se deu certo"><span class="rounded bg-red-950/50 px-1 text-[10px] text-red-300">{{ \App\Support\AttackSignatures::label($view->attack) }}</span></span>@endif
                                @if ($view->referer)<span class="block truncate text-[11px] text-ink-700" title="{{ $view->referer }}">de {{ $view->referer }}</span>@endif
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-xs {{ $view->status >= 400 ? 'text-red-300' : 'text-ink-400' }}">{{ $view->status }}</td>
                            <td class="hidden px-4 py-2 text-right font-mono text-xs whitespace-nowrap text-ink-400 sm:table-cell">{{ $time($view->duration_ms) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $recent->links() }}</div>
    </section>
@endsection
