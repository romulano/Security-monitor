@extends('layouts.admin')

@section('title', 'Prompts')

@section('content')
    <div class="mb-5">
        <h1 class="text-sm font-semibold text-white">Prompts do hermes</h1>
        <p class="max-w-3xl text-xs leading-5 text-ink-700">
            O texto que a IA recebe em cada etapa. A parte editável vale na próxima chamada; o formato da resposta e as
            regras sobre texto de terceiros ficam fixos, porque o código que lê a resposta depende deles. Toda edição fica
            no histórico e dá para voltar ao padrão. Etapa desligada é pulada: a IA não é chamada.
        </p>
    </div>

    <nav class="mb-6 flex flex-wrap gap-2 text-xs" aria-label="Filtrar por grupo">
        <a href="{{ route('admin.prompts.index') }}"
           class="rounded-full border px-3 py-1 {{ $current === null ? 'border-accent bg-accent/10 text-accent' : 'border-ink-800 text-ink-400 hover:border-ink-700 hover:text-ink-200' }}">Todos</a>
        @foreach ($filters as $key => $label)
            <a href="{{ route('admin.prompts.index', ['grupo' => $key]) }}"
               class="rounded-full border px-3 py-1 {{ $current === $key ? 'border-accent bg-accent/10 text-accent' : 'border-ink-800 text-ink-400 hover:border-ink-700 hover:text-ink-200' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <div class="space-y-8">
        @foreach ($groups as $group)
            <section>
                <h2 class="mb-3 border-b border-ink-800 pb-2 text-xs font-semibold tracking-widest text-ink-700 uppercase">{{ $group['label'] }}</h2>

                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($group['stages'] as $stage)
                        <div class="rounded-lg border bg-ink-900 p-4 {{ $stage['on'] ? 'border-ink-800' : 'border-dashed border-ink-800 opacity-75' }}">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="text-sm font-semibold text-white">
                                    <a href="{{ route('admin.prompts.show', $stage['slug']) }}" class="hover:text-accent">{{ $stage['label'] }}</a>
                                </h3>
                                @include('admin.prompts._toggle', ['stage' => $stage])
                            </div>
                            <p class="mt-1 text-xs leading-5 text-ink-400">{{ $stage['what'] }}</p>
                            <p class="mt-1 text-xs leading-5 text-ink-700">
                                {{ $stage['on'] ? $stage['when'] : 'Desligada: '.$stage['off'] }}
                            </p>

                            <ul class="mt-3 space-y-1">
                                @foreach ($stage['parts'] as $part)
                                    <li class="flex flex-wrap items-center gap-2 text-xs">
                                        <a href="{{ route('admin.prompts.show', $stage['slug']).'#'.$part['key'] }}" class="text-ink-200 hover:text-accent">{{ $part['label'] }}</a>
                                        @include('admin.prompts._state', ['part' => $part])
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection
