@extends('layouts.admin')

@section('title', 'Alertas · Perguntas')

@php
    use App\Models\InterviewQuestion;
    use App\Services\Admin\CatalogText;
    $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm';
    $form = $editing ?? ($creating ? new InterviewQuestion(['active' => true, 'step' => 4, 'kind' => 'multi', 'options' => []]) : null);
    $system = $form?->is_system ?? false;
@endphp

@section('content')
    @include('admin.alerts._nav', ['subtitle' => 'Mudanças valem para quem abrir a entrevista depois. Respostas antigas continuam guardadas.'])

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-white">Perguntas da entrevista <span class="font-normal text-ink-400">· {{ $questions->count() }}</span></h2>
        <a href="{{ route('admin.alerts.questions', ['nova' => 1]) }}" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">+ Nova pergunta</a>
    </div>

    <div class="grid items-start gap-4 {{ $form ? 'xl:grid-cols-[minmax(0,1fr)_24rem]' : '' }}">
        <div class="overflow-x-auto rounded-lg border border-ink-800">
            <table class="w-full min-w-[52rem] text-left text-sm">
                <thead class="bg-ink-900 text-[11px] tracking-widest text-ink-400 uppercase">
                    <tr>
                        <th class="px-3 py-2 font-medium">Ordem</th>
                        <th class="px-3 py-2 font-medium">Passo</th>
                        <th class="px-3 py-2 font-medium">Pergunta</th>
                        <th class="px-3 py-2 font-medium">Tipo</th>
                        <th class="px-3 py-2 font-medium">Opções</th>
                        <th class="px-3 py-2 font-medium">Aparece se</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-800">
                    @foreach ($questions as $q)
                        <tr class="{{ $editing?->id === $q->id ? 'bg-accent/5' : '' }} {{ $q->active ? '' : 'opacity-60' }}">
                            <td class="px-3 py-1.5 whitespace-nowrap">
                                @foreach (['cima' => '↑', 'baixo' => '↓'] as $dir => $arrow)
                                    <form method="POST" action="{{ route('admin.alerts.questions.move', $q) }}" class="inline">@csrf
                                        <input type="hidden" name="direcao" value="{{ $dir }}">
                                        <button aria-label="{{ $dir === 'cima' ? 'Subir' : 'Descer' }}" class="size-8 rounded-md border border-ink-800 text-ink-400 hover:text-white">{{ $arrow }}</button>
                                    </form>
                                @endforeach
                            </td>
                            <td class="px-3 py-2">{{ $q->step }}</td>
                            <td class="px-3 py-2 text-white">
                                {{ $q->prompt }}@if ($q->required)<span class="text-accent"> *</span>@endif
                                <span class="block font-mono text-xs text-ink-400">{{ $q->key }}{{ $q->is_system ? ' · sistema' : '' }}{{ $q->active ? '' : ' · inativa' }}</span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $q->kindLabel() }}</td>
                            <td class="px-3 py-2 text-xs text-ink-400">
                                {{ $q->kind === 'catalog' ? 'catálogo: '.$q->catalog_category.($q->filter_by ? ', filtrado por '.$q->filter_by : '') : (count($q->options ?? []) ? count($q->options).' opções' : '—') }}
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-ink-400">{{ $q->show_if ? $q->show_if['key'].' ∈ '.implode(', ', $q->show_if['in'] ?? []) : '—' }}</td>
                            <td class="px-3 py-2 text-right"><a href="{{ route('admin.alerts.questions', ['editar' => $q->id]) }}" class="text-accent hover:underline">Editar</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($form)
            <div class="space-y-3">
                <form method="POST" action="{{ $editing ? route('admin.alerts.questions.update', $editing) : route('admin.alerts.questions.store') }}"
                      class="space-y-4 rounded-lg border border-accent/40 bg-ink-900 p-4">
                    @csrf
                    @if ($editing) @method('PUT') @endif
                    <div class="flex items-baseline justify-between">
                        <h3 class="text-sm font-semibold text-white">{{ $editing ? 'Editar pergunta' : 'Nova pergunta' }}</h3>
                        <a href="{{ route('admin.alerts.questions') }}" class="text-xs text-ink-400 hover:text-ink-200">fechar</a>
                    </div>
                    @if ($system)
                        <p class="rounded-lg border border-ink-800 px-3 py-2 text-xs text-ink-400">
                            Pergunta de sistema: a resposta alimenta o perfil ({{ $form->key }}), então tipo e opções não mudam aqui.
                        </p>
                    @endif

                    <label class="block"><span class="mb-1 block text-xs text-ink-400">Pergunta</span>
                        <input name="prompt" value="{{ old('prompt', $form->prompt) }}" required class="{{ $input }}">
                        @error('prompt') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
                    </label>
                    <label class="block"><span class="mb-1 block text-xs text-ink-400">Ajuda (opcional)</span>
                        <textarea name="help" rows="2" class="{{ $input }}">{{ old('help', $form->help) }}</textarea>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="mb-1 block text-xs text-ink-400">Passo</span>
                            <select name="step" class="{{ $input }} [color-scheme:dark]">
                                @foreach (array_slice(InterviewQuestion::STEPS, 0, 6, true) as $n => [$title])
                                    <option value="{{ $n }}" @selected((int) old('step', $form->step) === $n)>{{ $n }} · {{ $title }}</option>
                                @endforeach
                            </select>
                        </label>
                        @unless ($editing)
                            <label class="block"><span class="mb-1 block text-xs text-ink-400">Chave</span>
                                <input name="key" value="{{ old('key') }}" required pattern="[a-z][a-z0-9_]*" class="{{ $input }} font-mono">
                                @error('key') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
                            </label>
                        @endunless
                    </div>

                    @unless ($system)
                        <label class="block"><span class="mb-1 block text-xs text-ink-400">Tipo</span>
                            <select name="kind" class="{{ $input }} [color-scheme:dark]">
                                @foreach (['multi' => 'múltipla escolha', 'single' => 'escolha única', 'catalog' => 'catálogo de tecnologias', 'tags' => 'lista de termos (vira busca)', 'text' => 'texto'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('kind', $form->kind) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block"><span class="mb-1 block text-xs text-ink-400">Categoria do catálogo (para o tipo catálogo)</span>
                            <select name="catalog_category" class="{{ $input }} [color-scheme:dark]">
                                <option value="">—</option>
                                @foreach ($categories as $value => $label)
                                    <option value="{{ $value }}" @selected(old('catalog_category', $form->catalog_category) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('catalog_category') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
                        </label>
                        <label class="block"><span class="mb-1 block text-xs text-ink-400">Opções (uma por linha: valor|rótulo|descrição)</span>
                            <textarea name="options" rows="4" class="{{ $input }} font-mono text-xs">{{ old('options', CatalogText::optionsToText($form->options ?? [])) }}</textarea>
                            @error('options') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
                        </label>
                    @endunless

                    <div class="flex flex-wrap gap-4 text-sm text-ink-200">
                        <label class="flex items-center gap-2"><input type="hidden" name="required" value="0"><input type="checkbox" name="required" value="1" @checked(old('required', $form->required)) class="accent-accent"> Obrigatória</label>
                        <label class="flex items-center gap-2"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $form->active)) class="accent-accent"> Ativa</label>
                    </div>

                    <button class="w-full rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">{{ $editing ? 'Salvar pergunta' : 'Criar pergunta' }}</button>
                </form>

                @if ($editing && ! $editing->is_system)
                    <form method="POST" action="{{ route('admin.alerts.questions.destroy', $editing) }}" data-confirm="Apagar esta pergunta? As respostas já dadas ficam guardadas.">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm text-red-300 hover:text-red-200">Apagar pergunta</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
@endsection
