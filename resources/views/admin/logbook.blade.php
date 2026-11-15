@extends('layouts.admin')

@section('title', 'Diário de bordo')

@section('content')
    @php
        $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        // Falhou o "editar" de um registro: o formulário de baixo não herda o que foi digitado lá.
        $failedEdit = old('_entry');
    @endphp

    <div class="mb-6">
        <h1 class="text-sm font-semibold text-white">Diário de bordo</h1>
        <p class="text-xs text-ink-700">
            Os registros que aparecem na página <a href="{{ route('about') }}" target="_blank" rel="noopener" class="text-accent hover:underline">/sobre</a>.
            Com mais de 4 registros, a página mostra os 3 mais recentes e guarda os outros atrás de “ver mais”; a “Próxima missão” fica sempre por último.
        </p>
    </div>

    <details class="mb-6 rounded-lg border border-ink-800 bg-ink-900 p-4" @if ($errors->any() && ! $failedEdit) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-ink-200">Adicionar registro</summary>

        <form method="POST" action="{{ route('admin.logbook.store') }}" class="mt-4 grid gap-3 sm:grid-cols-[10rem_1fr]">
            @csrf
            <label class="block">
                <span class="{{ $label }}">Data</span>
                <input name="logged_on" type="date" required value="{{ ($failedEdit ? null : old('logged_on')) ?? now('America/Sao_Paulo')->toDateString() }}" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="{{ $label }}">Título</span>
                <input name="title" required maxlength="120" value="{{ $failedEdit ? '' : old('title') }}" class="{{ $input }}">
            </label>
            <label class="block sm:col-span-2">
                <span class="{{ $label }}">O que mudou</span>
                <textarea name="text" required rows="3" maxlength="600" class="{{ $input }}">{{ $failedEdit ? '' : old('text') }}</textarea>
            </label>
            <div class="sm:col-span-2">
                <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Adicionar</button>
            </div>
        </form>
    </details>

    <div class="space-y-2">
        @forelse ($entries as $entry)
            @php $mine = (int) old('_entry') === $entry->id; @endphp
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-[11.5px] tracking-wide text-accent">{{ $entry->logged_on->format('d.m.Y') }}</p>
                        <h2 class="mt-1 text-sm font-semibold text-white">{{ $entry->title }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-ink-400">{{ $entry->text }}</p>
                    </div>

                    <form method="POST" action="{{ route('admin.logbook.destroy', $entry) }}" class="shrink-0"
                          data-confirm="Remover o registro “{{ $entry->title }}” do diário de bordo?">
                        @csrf
                        @method('DELETE')
                        <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-700 hover:text-red-400">Remover</button>
                    </form>
                </div>

                <details class="mt-3" @if ($mine) open @endif>
                    <summary class="cursor-pointer text-[11px] text-ink-700 hover:text-ink-400">editar</summary>
                    <form method="POST" action="{{ route('admin.logbook.update', $entry) }}" class="mt-3 grid gap-3 sm:grid-cols-[10rem_1fr]">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_entry" value="{{ $entry->id }}">
                        <label class="block">
                            <span class="{{ $label }}">Data</span>
                            <input name="logged_on" type="date" required value="{{ $mine ? old('logged_on') : $entry->logged_on->toDateString() }}" class="{{ $input }}">
                        </label>
                        <label class="block">
                            <span class="{{ $label }}">Título</span>
                            <input name="title" required maxlength="120" value="{{ $mine ? old('title') : $entry->title }}" class="{{ $input }}">
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="{{ $label }}">O que mudou</span>
                            <textarea name="text" required rows="3" maxlength="600" class="{{ $input }}">{{ $mine ? old('text') : $entry->text }}</textarea>
                        </label>
                        <div class="sm:col-span-2">
                            <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">Salvar</button>
                        </div>
                    </form>
                </details>
            </div>
        @empty
            <p class="rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm text-ink-400">
                Nenhum registro ainda. A /sobre mostra só a “Próxima missão” até você adicionar o primeiro.
            </p>
        @endforelse
    </div>
@endsection
