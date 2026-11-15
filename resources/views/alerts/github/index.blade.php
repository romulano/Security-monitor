@extends('layouts.app')

@section('title', 'Monitoramento do GitHub · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Models\GithubFinding;
    use App\Services\Github\GithubPanel;

    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m \à\s H:i');
@endphp

@section('content')
    @if (! $monitor?->isCompleted())
        {{-- Ainda sem monitoramento: a página vira o convite para a entrevista. --}}
        <div class="mx-auto max-w-2xl py-8">
            @include('alerts.partials.crumbs', ['here' => 'GitHub'])
            <h1 class="text-2xl font-semibold text-white">Saiba quando algo seu aparecer no GitHub público</h1>
            <p class="mt-2 text-ink-200">
                Chaves que um desenvolvedor subiu por engano, código privado publicado, repositórios que imitam a sua marca.
                Uma vez por dia o Security Monitor procura e avisa no Slack, no Discord, no Telegram ou num webhook.
            </p>
            @include('alerts.github.partials.tabs')
            <ul class="mt-5 space-y-2 text-sm text-ink-200">
                <li class="flex gap-2"><span class="text-accent">1.</span> Uma entrevista própria, separada da de alertas: organizações, nomes de chaves, endereços internos, marca. Uns 3 minutos.</li>
                <li class="flex gap-2"><span class="text-accent">2.</span> Só o nome das chaves, nunca o valor. Nos achados, o valor aparece mascarado.</li>
                <li class="flex gap-2"><span class="text-accent">3.</span> Cada achado passa por duas análises da IA: a classificação (Critical a Low) e o parecer de envio, que aprende com as suas avaliações.</li>
                <li class="flex gap-2"><span class="text-accent">4.</span> Você escolhe a partir de qual nível quer ser avisado.</li>
            </ul>
            @if ($monitor ? $canManage : $canCreate)
                <a href="{{ route('alerts.github.interview', $monitor?->urlParams(['passo' => $monitor->step_reached]) ?? ['passo' => 1]) }}"
                   class="mt-6 {{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">
                    {{ $monitor && $monitor->step_reached > 1 ? 'Continuar a entrevista (passo '.$monitor->step_reached.')' : 'Começar a entrevista' }}
                </a>
            @else
                <p class="mt-6 text-sm text-ink-400">
                    {{ $monitor ? 'A entrevista deste monitoramento ainda não terminou.' : 'Nenhum monitoramento do GitHub dos seus setores por enquanto.' }}
                    Quem configura o GitHub é quem administra a empresa ou quem responde por um setor. Peça a uma dessas pessoas.
                </p>
            @endif
        </div>
    @else
        @php
            $stats = $monitor->stats ?? [];
            $running = in_array($monitor->scan_status, ['pending', 'running'], true);
            $pill = match (true) {
                $monitor->isPaused() => ['pausado', 'border-ink-700 bg-ink-850 text-ink-400', 'bg-ink-400'],
                $running => [$monitor->scan_status === 'running' ? 'varrendo agora' : 'varredura na fila', 'border-accent/40 bg-accent/10 text-amber-200', 'bg-accent animate-pulse'],
                $monitor->scan_status === 'failed' => ['última varredura falhou', 'border-red-900 bg-red-950/50 text-red-300', 'bg-red-400'],
                default => ['ativo', 'border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
            };
        @endphp

        <div class="space-y-5">
            <div class="max-w-3xl">
                @include('alerts.partials.crumbs', ['here' => 'GitHub'])
                <h1 class="text-xl font-semibold text-white">Monitoramento do GitHub</h1>
                <p class="text-sm text-ink-400">
                    O que só deveria existir dentro da empresa, procurado todo dia em código e repositórios públicos. A IA classifica cada achado
                    e, nos níveis que você escolheu, decide se vale o aviso.
                </p>
                @include('alerts.github.partials.tabs')
            </div>

            {{-- Situação da varredura --}}
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-ink-800 bg-ink-900 px-5 py-4">
                <div class="min-w-0 flex-[1_1_420px] space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[15px] font-semibold text-white">{{ collect($monitor->orgs)->map(fn ($o) => 'github.com/'.$o)->first() ?? ($monitor->brands[0] ?? 'GitHub') }}</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $pill[1] }}">
                            <span class="size-1.5 rounded-full {{ $pill[2] }}"></span>{{ $pill[0] }}
                        </span>
                        <span class="rounded-full border border-ink-700 px-2.5 py-0.5 text-xs text-ink-200">aviso: {{ $monitor->levelLabel() }}</span>
                    </div>
                    <p class="text-sm text-ink-400">
                        @if ($monitor->last_scan_at)
                            Última varredura {{ $when($monitor->last_scan_at) }}:
                            {{ $stats['queries'] ?? 0 }} buscas, {{ $stats['results'] ?? 0 }} resultados, {{ $stats['new'] ?? 0 }} {{ ($stats['new'] ?? 0) === 1 ? 'achado novo' : 'achados novos' }}.
                            @if ($monitor->isActive() && $monitor->next_scan_at)
                                Próxima {{ $when($monitor->next_scan_at) }}.
                            @endif
                        @else
                            A primeira varredura leva uns minutos: a busca em código do GitHub aceita 10 buscas por minuto.
                        @endif
                    </p>
                    @unless ($codeSearch)
                        <p class="text-sm text-amber-200">A busca em código está indisponível no momento: por enquanto rodam só as buscas de repositórios.</p>
                    @endunless
                    @if ($monitor->last_error && ! $running)
                        <p class="text-sm text-amber-200">A última varredura parou antes do fim ({{ $monitor->last_error }}). O que faltou fica para a próxima.</p>
                    @endif
                </div>
                @if ($canManage)
                <div class="flex flex-wrap items-center gap-2">
                    @if ($monitor->isActive())
                        <form method="POST" action="{{ route('alerts.github.scan', $monitor->urlParams()) }}">
                            @csrf
                            <button @disabled($running || $monitor->manualScanAvailableAt())
                                    title="{{ $monitor->manualScanAvailableAt() ? 'Liberado de novo em '.$when($monitor->manualScanAvailableAt()) : 'Busca tudo de novo agora (1 vez por dia)' }}"
                                    class="{{ $button }} border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white disabled:cursor-not-allowed disabled:opacity-50">Verificar agora</button>
                        </form>
                    @endif
                    <a href="{{ route('alerts.github.interview', $monitor->urlParams(['passo' => 1])) }}" class="{{ $button }} text-ink-200 hover:text-white">Editar entrevista</a>
                    <form method="POST" action="{{ route('alerts.github.pause', $monitor->urlParams()) }}">
                        @csrf
                        <button class="{{ $button }} text-ink-400 hover:text-white">{{ $monitor->isActive() ? 'Pausar' : 'Retomar' }}</button>
                    </form>
                    <form method="POST" action="{{ route('alerts.github.destroy', $monitor->urlParams()) }}"
                          data-confirm="Excluir o monitoramento do GitHub? As respostas da entrevista e todos os achados serão apagados.">
                        @csrf
                        @method('DELETE')
                        <button class="{{ $button }} text-red-300 hover:text-red-200">Excluir</button>
                    </form>
                </div>
                @endif
                @if ($monitor->last_log)
                    <details class="w-full border-t border-ink-850 pt-3 text-sm">
                        <summary class="cursor-pointer text-ink-400 hover:text-ink-200">Como foi a última varredura</summary>
                        <ul class="mt-2 space-y-0.5 font-mono text-xs text-ink-200">
                            @foreach ($monitor->last_log as $line)
                                <li class="break-all">· {{ $line }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>

            @if (! $ownChannels)
                {{-- Os canais são de quem criou o monitoramento (até a separação por setor): quem só vê não tem o que ligar. --}}
            @elseif ($channels->isEmpty())
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-accent/40 bg-accent/5 px-4 py-3">
                    <p class="min-w-0 flex-[1_1_400px] text-sm text-ink-200">
                        <b class="text-white">Falta escolher onde receber.</b> Os achados ficam guardados aqui; conecte o Slack, o Discord, o Telegram ou um webhook para recebê-los.
                    </p>
                    <a href="{{ route('alerts.notifiers') }}" class="{{ $button }} bg-accent font-semibold text-ink-950">Escolher onde receber</a>
                </div>
            @elseif ($receivers->isEmpty())
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-800 bg-amber-950/30 px-4 py-3">
                    <p class="min-w-0 flex-[1_1_400px] text-sm text-amber-100">
                        <b class="text-white">Nenhum app recebe o GitHub.</b> Os achados ficam só aqui. Ligue o GitHub num app (ou retome o envio) em Notificadores.
                    </p>
                    <a href="{{ route('alerts.notifiers') }}" class="{{ $button }} bg-amber-300 font-semibold text-amber-950">Abrir Notificadores</a>
                </div>
            @endif

            <details class="rounded-xl border border-ink-800 bg-ink-900 px-5 py-4">
                <summary class="cursor-pointer text-sm font-semibold text-white">O que cada nível e cada porcentagem significam</summary>
                <div class="mt-4">@include('alerts.github.partials.levels')</div>
            </details>

            <section class="overflow-hidden rounded-xl border border-ink-800 bg-ink-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-800 px-5 py-3">
                    <div>
                        <h2 class="font-semibold text-white">Achados</h2>
                        <p class="text-sm text-ink-400">
                            {{ $counts['enviados'] ?? 0 }} enviado(s) · <span class="text-violet-300">{{ $counts['segurados-pela-ia'] ?? 0 }} segurado(s) pela IA</span>
                            · {{ $counts['abaixo-do-nivel'] ?? 0 }} abaixo do seu nível · {{ $counts['descartados'] ?? 0 }} descartado(s)
                        </p>
                    </div>
                    @include('alerts.partials.filters', [
                        'filters' => GithubPanel::FILTERS,
                        'current' => $filter,
                        'counts' => $counts,
                        'link' => fn ($value) => route('alerts.github', $monitor->urlParams(array_filter(['filtro' => $value === 'todos' ? null : $value]))),
                    ])
                </div>

                @forelse ($findings as $finding)
                    @include('alerts.github.partials.finding-row', ['finding' => $finding, 'canManage' => $canManage])
                @empty
                    <p class="px-5 py-8 text-center text-sm text-ink-400">
                        @switch($filter)
                            @case('pendentes') Nenhum achado pendente: tudo o que a IA deixou passar já foi avaliado. @break
                            @case('segurados-pela-ia') A IA não segurou nenhum achado. Quando ela julgar que um achado não merece aviso, ele aparece aqui. @break
                            @case('descartados') Nada descartado: a IA não achou falso positivo. @break
                            @case('abaixo-do-nivel') Nenhum achado abaixo do nível escolhido. @break
                            @default
                                {{ $monitor->last_scan_at ? 'Nenhum achado '.($filter === 'todos' ? 'até agora. Ótimo sinal: continuamos procurando todo dia.' : 'neste filtro.') : 'A primeira varredura ainda não terminou.' }}
                        @endswitch
                    </p>
                @endforelse

                @if ($findings?->hasPages())
                    <div class="border-t border-ink-850 px-5 py-3 text-sm">{{ $findings->links() }}</div>
                @endif
            </section>
        </div>
    @endif
@endsection
