@php
    /** @var \App\Models\InterviewQuestion $question */
    $question = $q['model'];
    $key = $question->key;
    $name = "answers[{$key}]";
    $current = old("answers.{$key}", $q['value']);
    $selected = array_map('strval', array_filter((array) $current, fn ($v) => $v !== null));
    $hasDesc = collect($q['options'])->contains(fn ($o) => filled($o['desc']));
    $chipClass = 'group inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-full border border-ink-700 bg-ink-850 px-3.5 text-sm text-ink-200 '
        .'hover:border-ink-400 has-checked:border-accent has-checked:bg-accent/10 has-checked:text-amber-200 '
        .'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent';
@endphp

<section data-question="{{ $key }}"
         @if ($question->show_if) data-show-if="{{ json_encode($question->show_if) }}" @endif
         @unless ($q['visible']) hidden @endunless
         class="rounded-xl border border-ink-800 bg-ink-900 p-5">

    @if ($question->kind === 'boolean')
        <label class="flex cursor-pointer items-center justify-between gap-4">
            <span class="min-w-0">
                <span class="block text-[15px] font-semibold text-white">{{ $question->prompt }}</span>
                @if ($question->help)
                    <span class="mt-0.5 block text-sm text-ink-400">{{ $question->help }}</span>
                @endif
            </span>
            <input type="hidden" name="{{ $name }}" value="0">
            <input type="checkbox" name="{{ $name }}" value="1" @checked(filter_var($current, FILTER_VALIDATE_BOOL)) class="peer sr-only">
            <span aria-hidden="true"
                  class="relative h-6 w-11 shrink-0 rounded-full bg-ink-700 transition peer-checked:bg-accent
                         peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent
                         after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-ink-200 after:transition
                         peer-checked:after:translate-x-5 peer-checked:after:bg-ink-950"></span>
        </label>
    @else
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-3">
            @if (in_array($question->kind, ['text', 'tags'], true))
                <label for="q-{{ $key }}" class="text-[15px] font-semibold text-white">
                    {{ $question->prompt }}@if ($question->required)<span class="text-accent" aria-hidden="true"> *</span>@endif
                </label>
            @else
                <h3 id="q-{{ $key }}-label" class="text-[15px] font-semibold text-white">
                    {{ $question->prompt }}@if ($question->required)<span class="text-accent" aria-hidden="true"> *</span>@endif
                </h3>
            @endif
            <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] tracking-wider text-ink-400 uppercase">{{ $question->kindLabel() }}</span>
        </div>

        @if ($question->help)
            <p class="-mt-1 mb-3 text-sm text-ink-400">{{ $question->help }}</p>
        @endif

        @switch($question->kind)
            @case('text')
                <input id="q-{{ $key }}" name="{{ $name }}" type="text" maxlength="255"
                       value="{{ is_array($current) ? '' : $current }}"
                       @if ($question->required) required @endif
                       @if ($key === 'domain') placeholder="empresa.com.br" autocomplete="off" spellcheck="false" @endif
                       class="min-h-11 w-full max-w-md rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent {{ $key === 'domain' ? 'font-mono' : '' }}">
                @break

            @case('tags')
                <div data-tags data-name="{{ $name }}[]"
                     class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-850 p-2 focus-within:border-accent">
                    @foreach ((array) $current as $item)
                        @continue(trim((string) $item) === '')
                        <span data-tag class="inline-flex items-center gap-1 rounded-md bg-ink-800 py-0.5 pr-1 pl-2.5 text-sm text-white">
                            {{ $item }}
                            <input type="hidden" name="{{ $name }}[]" value="{{ $item }}">
                            <button type="button" data-tag-remove aria-label="Remover {{ $item }}"
                                    class="grid size-7 place-items-center rounded text-ink-400 hover:text-white">&times;</button>
                        </span>
                    @endforeach
                    <input id="q-{{ $key }}" data-tags-input name="{{ $name }}[]" type="text" autocomplete="off"
                           placeholder="Digite e tecle Enter"
                           class="min-h-8 min-w-40 flex-1 border-0 bg-transparent px-1 text-sm text-white placeholder:text-ink-400 focus:outline-none">
                </div>
                @break

            @case('single')
                @if ($hasDesc)
                    <div role="radiogroup" aria-labelledby="q-{{ $key }}-label" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($q['options'] as $opt)
                            <label class="group flex cursor-pointer flex-col gap-1.5 rounded-lg border border-ink-700 bg-ink-850 p-3.5 hover:border-ink-400
                                          has-checked:border-accent has-checked:bg-accent/10 has-focus-visible:outline-2 has-focus-visible:outline-accent">
                                <span class="flex items-center gap-2 text-sm font-semibold text-ink-200 group-has-checked:text-amber-200">
                                    <input type="radio" name="{{ $name }}" value="{{ $opt['value'] }}" @checked(in_array($opt['value'], $selected, true))
                                           class="size-4 accent-accent">
                                    {{ $opt['label'] }}
                                </span>
                                @if ($key === 'min_criticity')
                                    @php $levels = array_slice(\App\Models\AlertProfile::LEVELS, 0, array_search($opt['value'], \App\Models\AlertProfile::LEVELS, true) + 1); @endphp
                                    <span class="flex flex-wrap gap-1">
                                        @foreach ($levels as $level)
                                            <x-criticity :level="$level" size="xs" />
                                        @endforeach
                                    </span>
                                @endif
                                @if ($opt['desc'])
                                    <span class="text-xs text-ink-400">{{ $opt['desc'] }}</span>
                                @endif
                                @if ($opt['recommended'])
                                    <span class="text-xs text-accent">recomendado</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                @else
                    <div role="radiogroup" aria-labelledby="q-{{ $key }}-label" class="flex flex-wrap gap-2">
                        @foreach ($q['options'] as $opt)
                            <label class="inline-flex min-h-11 min-w-22 cursor-pointer items-center justify-center rounded-lg border border-ink-700 bg-ink-850 px-4 text-sm text-ink-200 hover:border-ink-400
                                          has-checked:border-accent has-checked:bg-accent/10 has-checked:text-amber-200 has-focus-visible:outline-2 has-focus-visible:outline-accent">
                                <input type="radio" name="{{ $name }}" value="{{ $opt['value'] }}" @checked(in_array($opt['value'], $selected, true)) class="sr-only">
                                {{ $opt['label'] }}
                            </label>
                        @endforeach
                    </div>
                @endif
                @break

            @case('versions')
                @if ($q['options'] === [])
                    <p class="text-sm text-ink-400">Marque as tecnologias nos passos 2 a 4 e elas aparecem aqui.</p>
                @else
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($q['options'] as $opt)
                            <label class="flex min-h-11 items-center justify-between gap-3 rounded-lg border border-ink-700 bg-ink-850 py-1.5 pr-1.5 pl-3 focus-within:border-accent">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm text-ink-200">{{ $opt['label'] }}</span>
                                    @if ($opt['desc'])
                                        <span class="block text-xs text-ink-400">{{ $opt['desc'] }}</span>
                                    @endif
                                </span>
                                <input type="text" name="{{ $name }}[{{ $opt['value'] }}]" value="{{ is_array($current) ? ($current[$opt['value']] ?? '') : '' }}"
                                       maxlength="30" autocomplete="off" spellcheck="false" placeholder="versão"
                                       class="min-h-9 w-28 shrink-0 rounded-md border border-ink-700 bg-ink-900 px-2.5 font-mono text-sm text-white placeholder:text-ink-400 focus:border-accent">
                            </label>
                        @endforeach
                    </div>
                @endif
                @break

            @default
                @php
                    // Frameworks: um bloco por linguagem, escondido se a linguagem não foi marcada.
                    // Pergunta com mais de uma categoria ("cms,builder"): um bloco por categoria.
                    $byCategory = ! $question->filter_by && str_contains((string) $question->catalog_category, ',');
                    $groups = match (true) {
                        (bool) $question->filter_by => collect($q['options'])->filter(fn ($o) => $o['parent'])->groupBy('parent'),
                        $byCategory => collect($q['options'])->groupBy(fn ($o) => $o['group'] ?? ''),
                        default => collect(['' => $q['options']]),
                    };
                @endphp
                <div class="space-y-4"
                     @if ($question->filter_by) data-filter-by="{{ $question->filter_by }}" data-filter-map="{{ json_encode((object) $q['filter_map']) }}" @endif>
                    @foreach ($groups as $parent => $opts)
                        <div class="space-y-2"
                             @if ($question->filter_by) data-parent-group="{{ $parent }}" @unless (in_array($parent, $q['filter'] ?? [], true)) hidden @endunless @endif>
                            @if ($question->filter_by && $groups->count() > 1)
                                <p class="text-[11px] tracking-widest text-ink-400 uppercase">{{ $opts->first()['parent_name'] }}</p>
                            @elseif ($byCategory && $parent !== '' && $groups->count() > 1)
                                <p class="text-[11px] tracking-widest text-ink-400 uppercase">{{ $parent }}</p>
                            @endif
                            <div class="flex flex-wrap gap-2" role="group" aria-labelledby="q-{{ $key }}-label">
                                @foreach ($opts as $opt)
                                    <label class="{{ $chipClass }}">
                                        <input type="checkbox" name="{{ $name }}[]" value="{{ $opt['value'] }}" @checked(in_array($opt['value'], $selected, true)) class="sr-only">
                                        <svg class="hidden size-3.5 group-has-checked:inline" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>
                                        {{ $opt['label'] }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    @if ($question->filter_by)
                        <p data-filter-empty class="text-sm text-ink-400" @unless (empty($q['filter'])) hidden @endunless>
                            Marque uma opção acima para ver as escolhas.
                        </p>
                    @endif

                    @if ($q['allows_other'] ?? false)
                        @php
                            $otherName = "answers[{$key}".\App\Services\Alerts\InterviewService::OTHER.']';
                            $otherItems = (array) old("answers.{$key}".\App\Services\Alerts\InterviewService::OTHER, $q['other'] ?? []);
                        @endphp
                        <div>
                            <label for="q-{{ $key }}-outros" class="mb-1.5 block text-sm text-ink-200">Não achou? Escreva aqui</label>
                            <div data-tags data-name="{{ $otherName }}[]"
                                 class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-850 p-2 focus-within:border-accent">
                                @foreach ($otherItems as $item)
                                    @continue(trim((string) $item) === '')
                                    <span data-tag class="inline-flex items-center gap-1 rounded-md bg-ink-800 py-0.5 pr-1 pl-2.5 text-sm text-white">
                                        {{ $item }}
                                        <input type="hidden" name="{{ $otherName }}[]" value="{{ $item }}">
                                        <button type="button" data-tag-remove aria-label="Remover {{ $item }}"
                                                class="grid size-7 place-items-center rounded text-ink-400 hover:text-white">&times;</button>
                                    </span>
                                @endforeach
                                <input id="q-{{ $key }}-outros" data-tags-input name="{{ $otherName }}[]" type="text" autocomplete="off" maxlength="80"
                                       placeholder="Digite e tecle Enter"
                                       class="min-h-8 min-w-40 flex-1 border-0 bg-transparent px-1 text-sm text-white placeholder:text-ink-400 focus:outline-none">
                            </div>
                            <p class="mt-1 text-xs text-ink-400">Pode escrever em português: o Security Monitor traduz para os termos usados nas notícias.</p>
                        </div>
                    @endif
                </div>
        @endswitch
    @endif

    @error("answers.{$key}")
        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
    @enderror
</section>
