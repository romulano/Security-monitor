@extends('layouts.admin')

@section('title', 'Fila')

@section('content')
    @php
        $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m H:i:s') ?? '—';
        $states = ['rodando' => 'bg-emerald-950/60 text-emerald-300', 'aguardando' => 'bg-ink-850 text-ink-400', 'agendado' => 'bg-sky-950/60 text-sky-300'];
    @endphp

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-sm font-semibold text-white">Fila de jobs</h1>
            <p class="max-w-3xl text-xs leading-relaxed text-ink-700">
                O que os workers do supervisor estão fazendo e o que espera por eles; os que falharam ficam com o motivo.
                <span class="text-violet-300">IA</span> marca os jobs que chamam o hermes. Um job falho pode voltar à fila
                (roda de novo do começo) ou ser apagado. Um job <span class="text-sky-300">agendado</span> pode ser adiantado:
                o worker da fila dele o pega na próxima volta.
            </p>
            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-ink-700">
                As tarefas do agendador (a fusão das 00:00, o Radar da 01:00, a coleta de hora em hora) não são jobs desta
                lista até a hora delas chegar: o botão não as cobre.
            </p>
        </div>

        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-xs text-ink-400">
            <input type="checkbox" data-auto-refresh="15" data-auto-refresh-key="secmon.queue.autorefresh" class="rounded border-ink-700 bg-ink-850 text-accent">
            atualizar sozinho
        </label>
    </div>

    <nav class="mb-4 flex gap-1 text-sm">
        @foreach (['fila' => 'Na fila', 'falhos' => 'Falhos'] as $key => $name)
            <a href="{{ route('admin.queue', $key === 'fila' ? [] : ['ver' => $key]) }}"
               class="rounded-md px-2.5 py-1.5 {{ $tab === $key ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-ink-200' }}">
                {{ $name }} <span class="font-mono {{ $key === 'falhos' && $counts['falhos'] ? 'text-red-300' : 'text-ink-700' }}">· {{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    @if ($tab === 'falhos' && $jobs->isNotEmpty())
        <div class="mb-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.queue.retry-all') }}" data-confirm="Devolver à fila todos os {{ $counts['falhos'] }} job(s) falho(s)? Cada um roda de novo do começo.">
                @csrf
                <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Tentar todos de novo</button>
            </form>
            <form method="POST" action="{{ route('admin.queue.forget-all') }}" data-confirm="Apagar os {{ $counts['falhos'] }} job(s) falho(s)? Eles não rodam mais.">
                @csrf
                @method('DELETE')
                <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-400 hover:border-red-800 hover:text-red-300">Apagar todos</button>
            </form>
        </div>
    @endif

    @if ($tab === 'fila' && $advanceable['total'] > 0)
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('admin.queue.advance-all') }}"
                  data-confirm="Adiantar {{ $advanceable['total'] }} job(s) agendado(s)? Cada um roda na próxima volta do worker da fila dele, em vez de esperar a hora marcada.{{ $advanceable['ai_limit'] ? ' '.$advanceable['ai_limit'].' deles esperam o limite da IA: se a conta ainda estiver no limite, voltam a esperar.' : '' }} Os passos do teste de segurança e as conferências do Radar ficam como estão.">
                @csrf
                <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">Adiantar todos os agendados</button>
            </form>
            <span class="text-xs text-ink-700">{{ $advanceable['total'] }} adiantável(is)</span>
        </div>
    @endif

    @if ($jobs->isEmpty())
        <p class="rounded-lg border border-ink-800 bg-ink-900 p-5 text-sm text-ink-400">
            {{ $tab === 'falhos' ? 'Nenhum job falho.' : 'Nenhum job na fila: os workers estão livres.' }}
        </p>
    @else
        <ul class="space-y-2">
            @foreach ($jobs as $job)
                <li class="rounded-lg border {{ $tab === 'falhos' ? 'border-red-900/50' : 'border-ink-800' }} bg-ink-900 p-4 text-sm" id="job-{{ $job['id'] }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-ink-200">
                            <span class="font-medium text-white">{{ $job['label'] }}</span>
                            @if ($job['ai'])<span class="ml-1 rounded bg-violet-950/60 px-1 text-[10px] text-violet-300">IA</span>@endif
                            @if ($tab === 'fila')<span class="ml-1 rounded px-1 text-[10px] {{ $states[$job['state']] }}">{{ $job['state'] }}</span>@endif
                            <span class="ml-1 font-mono text-[11px] text-ink-700">{{ $job['class'] }} · fila {{ $job['queue'] }}</span>
                        </p>
                        <p class="text-xs text-ink-700">
                            @if ($tab === 'falhos')
                                falhou em {{ $when($job['failed_at']) }}
                            @else
                                entrou {{ $when($job['created_at']) }}@if ($job['running_since']) · rodando desde {{ $when($job['running_since']) }}@endif
                            @endif
                        </p>
                    </div>

                    @foreach ($job['subjects'] as $subject)
                        <p class="mt-1 truncate text-xs text-ink-400">
                            @if ($subject['url'])
                                <a href="{{ $subject['url'] }}" target="_blank" class="hover:text-accent">{{ $subject['text'] }} ↗</a>
                            @else
                                {{ $subject['text'] }}
                            @endif
                        </p>
                    @endforeach

                    <p class="mt-2 text-xs {{ $tab === 'falhos' ? 'text-red-300' : 'text-ink-400' }}">
                        <span class="text-ink-700">Motivo:</span> {{ $job['reason'] }}
                    </p>

                    @if ($tab === 'fila' && $job['state'] === 'agendado' && $job['hold']['advance'])
                        <form method="POST" action="{{ route('admin.queue.advance', $job['id']) }}" class="mt-3"
                              @if ($job['hold']['ai_limit']) data-confirm="Adiantar este job ({{ $job['label'] }})? Ele espera o limite da IA: se a conta ainda estiver no limite, volta a esperar." @endif>
                            @csrf
                            <button class="rounded-lg border border-ink-800 px-3 py-1.5 text-xs text-ink-200 hover:border-accent">Adiantar</button>
                        </form>
                    @endif

                    @if ($tab === 'falhos')
                        <div class="mt-3 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.queue.retry', $job['uuid']) }}">
                                @csrf
                                <button class="rounded-lg border border-ink-800 px-3 py-1.5 text-xs text-ink-200 hover:border-accent">Tentar de novo</button>
                            </form>
                            <form method="POST" action="{{ route('admin.queue.forget', $job['uuid']) }}" data-confirm="Apagar este job falho ({{ $job['label'] }})? Ele não roda mais.">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-lg border border-ink-800 px-3 py-1.5 text-xs text-ink-400 hover:border-red-800 hover:text-red-300">Apagar</button>
                            </form>
                        </div>
                        <details class="mt-2 text-xs">
                            <summary class="cursor-pointer text-ink-700 hover:text-ink-400">Erro completo · uuid <span class="font-mono">{{ $job['uuid'] }}</span></summary>
                            <pre class="mt-2 max-h-80 overflow-auto rounded bg-ink-950 p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap text-ink-400">{{ $job['trace'] }}</pre>
                        </details>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
@endsection
