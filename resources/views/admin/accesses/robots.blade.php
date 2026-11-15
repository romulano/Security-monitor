@extends('layouts.admin')

@section('title', 'Acessos · Robôs')

@section('content')
    @php
        $when = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->timezone('America/Sao_Paulo')->format('d/m H:i:s') : '—';
        $num = fn ($value) => number_format((int) $value, 0, ',', '.');
        // O clique no dono ou no tipo filtra a própria aba; "acessos ›" leva o robô para a Visão geral, com os filtros comuns.
        $defaults = ['periodo' => '24h', 'robos' => 'com', 'nossos' => 'esconder'];
        $clean = fn (array $values) => array_filter($values, fn ($v, $k) => $v !== '' && $v !== null && ($defaults[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        $filter = fn (array $change) => route('admin.accesses.robots', $clean(array_merge($filters, $change)));
        $overview = fn (object $row) => route('admin.accesses', $clean(['tipo' => $row->kind, 'dono' => $row->owner] + \Illuminate\Support\Arr::only($filters, ['periodo', 'pais', 'ip', 'nossos'])));
        $kindLabel = fn (?string $kind) => \App\Support\RobotCatalog::label($kind);
        $declared = 'o que o robô declara no navegador (User-Agent)';
        // "Ver mais" sem JS: ?mais=robos|ias mostra o quadro inteiro (30, 20); sem ele, os primeiros.
        $robots = $mais === 'robos' ? $byRobot : $byRobot->take(15);
        $stories = $mais === 'ias' ? $aiAsked : $aiAsked->take(10);
        $seeMore = 'mt-2 inline-block text-xs text-ink-400 hover:text-accent';
    @endphp

    @include('admin.accesses._tabs', ['route' => 'admin.accesses.robots'])
    @include('admin.accesses._filters', ['route' => 'admin.accesses.robots', 'fields' => ['ip', 'pais', 'pagina', 'nossos']])

    {{-- De quem é o robô (RobotCatalog): pelo que ele declara no navegador; o nosso é pelo IP. --}}
    <div class="mb-8 grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <section id="robos" class="min-w-0">
            <h2 class="mb-1 text-xs font-semibold tracking-widest text-ink-700 uppercase">Robôs por dono e tipo</h2>
            <details class="mb-3 max-w-3xl text-xs text-ink-700">
                <summary class="cursor-pointer text-ink-400 hover:text-ink-200">Por que um robô pode ser falso</summary>
                <p class="mt-1 leading-relaxed">
                    É {{ $declared }}, e qualquer um pode imitar. Na Cloudflare, os robôs verificados (Google, Bing, OpenAI, Anthropic...)
                    passam pelas regras da borda; os não verificados que vêm de nuvem (AWS, Alibaba, Tencent, Microsoft) ou que buscam
                    (?q=) recebem desafio e quase nunca chegam aqui. Um robô famoso vindo de outra rede pode ser falso: confira o IP.
                </p>
            </details>
            <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                        <tr>
                            <th class="px-4 py-2 font-medium">Dono</th>
                            <th class="hidden px-4 py-2 font-medium sm:table-cell">Tipo</th>
                            <th class="px-4 py-2 text-right font-medium">Acessos</th>
                            <th class="hidden px-4 py-2 text-right font-medium sm:table-cell">IPs</th>
                            <th class="hidden px-4 py-2 text-right font-medium sm:table-cell">Páginas</th>
                            <th class="px-4 py-2 text-right font-medium">Matérias</th>
                            <th class="hidden px-4 py-2 font-medium md:table-cell">Último</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-800">
                        @forelse ($robots as $row)
                            <tr>
                                <td class="px-4 py-2 text-xs">
                                    <a href="{{ $filter(['dono' => $row->owner, 'tipo' => $row->kind]) }}" class="{{ $row->owner ? 'text-ink-200' : 'text-ink-400' }} hover:text-accent">{{ $row->owner ?? 'sem dono' }}</a>
                                    <a href="{{ $overview($row) }}" class="ml-1 text-[11px] whitespace-nowrap text-ink-700 hover:text-accent">acessos ›</a>
                                    {{-- No celular, o tipo vai embaixo do dono, e a tabela cabe na tela. --}}
                                    <span class="block text-[11px] text-ink-700 sm:hidden">{{ $kindLabel($row->kind) }}</span>
                                </td>
                                <td class="hidden px-4 py-2 text-xs text-ink-400 sm:table-cell">
                                    <a href="{{ $filter(['tipo' => $row->kind, 'dono' => null]) }}" class="hover:text-accent">{{ $kindLabel($row->kind) }}</a>
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-ink-200">{{ $num($row->views) }}</td>
                                <td class="hidden px-4 py-2 text-right font-mono text-ink-400 sm:table-cell">{{ $num($row->ips) }}</td>
                                <td class="hidden px-4 py-2 text-right font-mono text-ink-400 sm:table-cell">{{ $num($row->pages) }}</td>
                                <td class="px-4 py-2 text-right font-mono text-ink-400">{{ $num($row->stories) }}</td>
                                <td class="hidden px-4 py-2 text-xs whitespace-nowrap text-ink-700 md:table-cell">{{ $when($row->last_at) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-4 text-sm text-ink-400">Nenhum robô declarado nesse recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($mais === 'robos')
                <a href="{{ $filter(['mais' => null]) }}#robos" class="{{ $seeMore }}">Ver menos</a>
            @elseif ($byRobot->count() > 15)
                <a href="{{ $filter(['mais' => 'robos']) }}#robos" class="{{ $seeMore }}">Ver os {{ $byRobot->count() }} robôs ›</a>
            @endif
        </section>

        <section id="ias" class="min-w-0">
            <h2 class="mb-1 text-xs font-semibold tracking-widest text-ink-700 uppercase">O que as IAs abriram a pedido de alguém</h2>
            <p class="mb-3 text-xs text-ink-700">O que o ChatGPT, o Claude, o Perplexity e cia. abriram para responder a uma pessoa.</p>
            <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                        <tr>
                            <th class="px-4 py-2 font-medium">Matéria</th>
                            <th class="px-4 py-2 text-right font-medium">Vezes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-800">
                        @forelse ($stories as $row)
                            <tr>
                                <td class="px-4 py-2 text-xs">
                                    <a href="{{ route('feed.show', $row->uuid) }}" class="text-ink-200 hover:text-accent">{{ $row->title ?? '/materia/'.$row->uuid }}</a>
                                    <span class="block text-[11px] text-ink-700">{{ $row->owners }} · {{ $when($row->last_at) }}</span>
                                </td>
                                <td class="px-4 py-2 text-right align-top font-mono text-ink-200">{{ $num($row->views) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-4 text-sm text-ink-400">Nenhuma matéria aberta por IA a pedido de alguém nesse recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($mais === 'ias')
                <a href="{{ $filter(['mais' => null]) }}#ias" class="{{ $seeMore }}">Ver menos</a>
            @elseif ($aiAsked->count() > 10)
                <a href="{{ $filter(['mais' => 'ias']) }}#ias" class="{{ $seeMore }}">Ver as {{ $aiAsked->count() }} matérias ›</a>
            @endif
        </section>
    </div>
@endsection
