@extends('layouts.admin')

@section('title', $stage['label'].' · Prompts')

@php
    $when = fn ($date) => $date->timezone('America/Sao_Paulo')->format('d/m/Y H:i');
    // Altura do campo pelo tamanho do texto: parágrafo longo numa linha só também conta.
    $rows = fn (string $text) => max(6, min(32, 1 + array_sum(array_map(
        fn (string $line) => max(1, (int) ceil(mb_strlen($line) / 130)),
        explode("\n", $text),
    ))));
@endphp

@section('content')
    <nav class="flex flex-wrap items-center gap-1.5 text-xs text-ink-700">
        <a href="{{ route('admin.prompts.index') }}" class="text-ink-400 hover:text-ink-200">← Prompts</a>
        <span>›</span>
        <a href="{{ route('admin.prompts.index', ['grupo' => $stage['group']]) }}" class="text-ink-400 hover:text-ink-200">{{ $stage['group_label'] }}</a>
    </nav>

    <div class="mt-2 mb-6 max-w-3xl">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-sm font-semibold text-white">{{ $stage['label'] }}</h1>
            @include('admin.prompts._toggle', ['stage' => $stage])
        </div>
        <p class="mt-1 text-xs leading-5 text-ink-400">{{ $stage['what'] }}</p>
        <p class="mt-1 text-xs leading-5 text-ink-700">Quando roda: {{ $stage['when'] }}</p>

        @unless ($stage['on'])
            <p class="mt-3 rounded-lg border border-amber-700/50 bg-amber-950/30 px-3 py-2 text-xs leading-5 text-amber-200">
                <strong class="font-semibold">Etapa desligada: é pulada e a IA não é chamada.</strong> {{ $stage['off'] }}
            </p>
        @endunless
    </div>

    <div class="space-y-6">
        @foreach ($stage['parts'] as $part)
            <section id="{{ $part['key'] }}" class="scroll-mt-4 rounded-lg border border-ink-800 bg-ink-900 p-5">
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-semibold text-white">{{ $part['label'] }}</h2>
                    @include('admin.prompts._state', ['part' => $part])
                </div>
                <p class="mb-3 text-xs text-ink-700">{{ $part['where'] }}</p>

                @if ($part['default_changed'])
                    <p class="mb-3 rounded-lg border border-amber-700/50 bg-amber-950/30 px-3 py-2 text-xs leading-5 text-amber-200">
                        O texto padrão mudou numa atualização do Security Monitor depois desta edição. Compare com o padrão abaixo: a
                        edição pode ter ficado sem o que entrou de novo.
                    </p>
                @endif

                <form method="POST" action="{{ route('admin.prompts.update', $part['key']) }}"
                      data-confirm="Salvar este prompt? A próxima chamada do hermes nesta etapa já usa o texto novo.">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="part" value="{{ $part['key'] }}">

                    <label for="body-{{ $part['key'] }}" class="sr-only">{{ $part['label'] }}</label>
                    <textarea id="body-{{ $part['key'] }}" name="body" rows="{{ $rows($part['text']) }}" spellcheck="false"
                              class="w-full rounded-lg border border-ink-800 bg-ink-950 px-3 py-2 font-mono text-[13px] leading-6 text-ink-200 focus:border-accent">{{ old('part') === $part['key'] ? old('body') : $part['text'] }}</textarea>

                    @foreach ($part['vars'] ?? [] as $var => $meaning)
                        <p class="mt-1 text-xs text-ink-700">
                            <code class="font-mono text-ink-400">{{ '{'.$var.'}' }}</code> é obrigatório: é onde entra {{ $meaning }}.
                        </p>
                    @endforeach

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="submit"
                                class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                            Salvar
                        </button>
                        @if ($part['edited'])
                            <button type="submit" form="reset-{{ $part['key'] }}"
                                    class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">
                                Restaurar padrão
                            </button>
                        @endif
                    </div>
                </form>

                @if ($part['edited'])
                    <form id="reset-{{ $part['key'] }}" method="POST" action="{{ route('admin.prompts.reset', $part['key']) }}"
                          data-confirm="Restaurar o texto padrão? A edição continua no histórico.">
                        @csrf
                    </form>

                    <details class="mt-4">
                        <summary class="cursor-pointer text-xs text-ink-400 hover:text-ink-200">Ver o texto padrão</summary>
                        <pre class="mt-2 max-h-96 overflow-auto rounded-lg border border-ink-800 bg-ink-950 px-3 py-2 font-mono text-[12px] leading-5 whitespace-pre-wrap text-ink-400">{{ $part['default'] }}</pre>
                    </details>
                @endif

                @if ($part['fixed'])
                    <div class="mt-4">
                        <p class="mb-1 text-[10px] tracking-widest text-ink-700 uppercase">Vem logo depois e não se edita</p>
                        <pre class="max-h-64 overflow-auto rounded-lg border border-dashed border-ink-800 px-3 py-2 font-mono text-[12px] leading-5 whitespace-pre-wrap text-ink-400">{{ $part['fixed'] }}</pre>
                        <p class="mt-1 text-xs text-ink-700">
                            O código que lê a resposta depende deste formato.
                            @if (str_contains($part['fixed'], '{código}'))
                                <code class="font-mono text-ink-400">{código}</code> é um código aleatório, novo a cada chamada, que cerca o texto de terceiros.
                            @endif
                        </p>
                    </div>
                @endif

                @if ($part['history'])
                    <details class="mt-4">
                        <summary class="cursor-pointer text-xs text-ink-400 hover:text-ink-200">Histórico ({{ count($part['history']) }})</summary>
                        <ol class="mt-2 space-y-2">
                            @foreach ($part['history'] as $i => $revision)
                                <li class="rounded-lg border border-ink-800 px-3 py-2">
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="text-ink-200">{{ $when($revision->created_at) }}</span>
                                        <span class="text-ink-700">{{ $revision->user?->name ?? 'sem usuário' }}</span>
                                        <span class="text-ink-400">{{ $revision->body === null ? 'voltou ao padrão' : 'editou' }}</span>
                                        @if ($i === 0)
                                            <span class="rounded bg-ink-850 px-1.5 py-0.5 text-[11px] text-ink-400">em uso</span>
                                        @else
                                            <form method="POST" action="{{ route('admin.prompts.restore', $revision) }}" class="ml-auto"
                                                  data-confirm="Voltar para esta versão? A próxima chamada do hermes já usa este texto.">
                                                @csrf
                                                <button type="submit" class="text-accent hover:underline">Voltar para esta versão</button>
                                            </form>
                                        @endif
                                    </div>
                                    @if ($revision->body !== null)
                                        <details class="mt-1">
                                            <summary class="cursor-pointer text-[11px] text-ink-700 hover:text-ink-400">Ver o texto</summary>
                                            <pre class="mt-1 max-h-72 overflow-auto font-mono text-[12px] leading-5 whitespace-pre-wrap text-ink-400">{{ $revision->body }}</pre>
                                        </details>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </details>
                @endif
            </section>
        @endforeach

        <section class="rounded-lg border border-ink-800 bg-ink-900 p-5">
            <h2 class="text-sm font-semibold text-white">O que o hermes recebe</h2>
            <p class="mt-1 text-xs leading-5 text-ink-700">
                Montado agora pelo mesmo código das chamadas reais, com o texto em uso acima. Nada é enviado à IA.
            </p>
            @if ($stage['preview']['example'])
                <p class="mt-2 text-xs text-ink-400">Exemplo: {{ $stage['preview']['example'] }}</p>
            @endif
            @if ($stage['preview']['note'])
                <p class="mt-1 text-xs text-ink-700">{{ $stage['preview']['note'] }}</p>
            @endif

            @foreach ($stage['preview']['messages'] as $i => $message)
                <details class="mt-4" @if ($message['role'] === 'system') open @endif>
                    <summary class="cursor-pointer text-xs text-ink-200">
                        {{ $message['role'] === 'system' ? 'Mensagem de sistema' : 'Mensagem do usuário' }}
                        <span class="text-ink-700">· {{ number_format(mb_strlen($message['content']), 0, ',', '.') }} caracteres</span>
                    </summary>
                    <div class="mt-2 flex justify-end">
                        <button type="button" data-copy="#preview-{{ $i }}" class="text-[11px] text-ink-400 hover:text-accent">Copiar</button>
                    </div>
                    <textarea id="preview-{{ $i }}" readonly rows="{{ $rows($message['content']) }}"
                              class="mt-1 w-full rounded-lg border border-ink-800 bg-ink-950 px-3 py-2 font-mono text-[12px] leading-5 text-ink-400">{{ $message['content'] }}</textarea>
                </details>
            @endforeach
        </section>
    </div>
@endsection
