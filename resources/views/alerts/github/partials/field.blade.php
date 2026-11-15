{{--
    Uma pergunta da entrevista do GitHub. Espera $f (GithubInterview::FIELDS + key, value, max).
    Mesmo visual da entrevista de alertas (alerts.partials.question).
--}}
@php
    use App\Models\GithubMonitor;
    use App\Services\Github\GithubInterview;

    $key = $f['key'];
    $name = "answers[{$key}]";
    $current = old("answers.{$key}", $f['value']);
@endphp

<section data-question="{{ $key }}" class="rounded-xl border border-ink-800 bg-ink-900 p-5">
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-3">
        @if (in_array($f['kind'], ['tags', 'textarea'], true))
            <label for="q-{{ $key }}" class="text-[15px] font-semibold text-white">{{ $f['label'] }}@if ($key === 'min_level')<span class="text-accent" aria-hidden="true"> *</span>@endif</label>
        @else
            <h3 id="q-{{ $key }}-label" class="text-[15px] font-semibold text-white">{{ $f['label'] }}@if ($key === 'min_level')<span class="text-accent" aria-hidden="true"> *</span>@endif</h3>
        @endif
        <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] tracking-wider text-ink-400 uppercase">
            {{ match ($f['kind']) { 'tags' => 'vários', 'textarea' => 'texto livre', 'single' => 'escolha única', default => 'várias escolhas' } }}
        </span>
    </div>

    @if ($f['help'] ?? null)
        <p class="-mt-1 mb-3 text-sm text-ink-400">{{ $f['help'] }}</p>
    @endif

    @switch($f['kind'])
        @case('tags')
            <div data-tags data-name="{{ $name }}[]"
                 class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-850 p-2 focus-within:border-accent">
                @foreach ((array) $current as $item)
                    @continue(trim((string) $item) === '')
                    <span data-tag class="inline-flex items-center gap-1 rounded-md bg-ink-800 py-0.5 pr-1 pl-2.5 font-mono text-sm text-white">
                        {{ $item }}
                        <input type="hidden" name="{{ $name }}[]" value="{{ $item }}">
                        <button type="button" data-tag-remove aria-label="Remover {{ $item }}"
                                class="grid size-7 place-items-center rounded text-ink-400 hover:text-white">&times;</button>
                    </span>
                @endforeach
                <input id="q-{{ $key }}" data-tags-input name="{{ $name }}[]" type="text" autocomplete="off" spellcheck="false" maxlength="120"
                       placeholder="{{ $f['placeholder'] ?? 'Digite e tecle Enter' }}"
                       class="min-h-8 min-w-40 flex-1 border-0 bg-transparent px-1 font-mono text-sm text-white placeholder:font-sans placeholder:text-ink-400 focus:outline-none">
            </div>
            <p class="mt-1 text-xs text-ink-400">Tecle Enter ou vírgula depois de cada item{{ $f['max'] ? ' · até '.$f['max'] : '' }}.</p>
            @if ($key === 'key_names')
                <p class="mt-2 flex gap-2 rounded-lg border border-amber-800/70 bg-amber-950/30 px-3 py-2 text-xs leading-5 text-amber-100">
                    <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                    <span>Só o nome. Um item com cara de valor (token, senha, texto longo e aleatório) é descartado na hora, sem ser salvo.</span>
                </p>
            @endif
            @break

        @case('textarea')
            @php $max = (int) config('secmon.github.extra_max', 1000); @endphp
            <textarea id="q-{{ $key }}" name="{{ $name }}" rows="6" maxlength="{{ $max }}" data-counter
                      placeholder="{{ $f['placeholder'] }}"
                      class="w-full resize-y rounded-lg border border-ink-700 bg-ink-850 px-3 py-2 text-sm text-white placeholder:text-ink-400 focus:border-accent">{{ $current }}</textarea>
            <div class="mt-1 flex flex-wrap justify-between gap-2 text-xs text-ink-400">
                <span>A IA lê só este texto, para tirar nomes de chaves, repositórios, endereços e o contexto. Instruções para a IA são ignoradas.</span>
                <span class="font-mono" data-counter-for="q-{{ $key }}">{{ mb_strlen((string) $current) }}/{{ $max }}</span>
            </div>
            @break

        @case('single')
            <div role="radiogroup" aria-labelledby="q-{{ $key }}-label" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (GithubInterview::LEVEL_OPTIONS as $value => [$label, $desc])
                    @php $levels = array_slice(GithubMonitor::LEVELS, 0, array_search($value, GithubMonitor::LEVELS, true) + 1); @endphp
                    <label class="group flex cursor-pointer flex-col gap-1.5 rounded-lg border border-ink-700 bg-ink-850 p-3.5 hover:border-ink-400
                                  has-checked:border-accent has-checked:bg-accent/10 has-focus-visible:outline-2 has-focus-visible:outline-accent">
                        <span class="flex items-center gap-2 text-sm font-semibold text-ink-200 group-has-checked:text-amber-200">
                            <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked($current === $value) class="size-4 accent-accent">
                            {{ $label }}
                        </span>
                        <span class="flex flex-wrap gap-1">
                            @foreach ($levels as $level)
                                <x-criticity :level="$level" size="xs" />
                            @endforeach
                        </span>
                        <span class="text-xs text-ink-400">{{ $desc }}</span>
                        @if ($value === 'high')
                            <span class="text-xs text-accent">recomendado</span>
                        @endif
                    </label>
                @endforeach
            </div>
            @break

        @case('checks')
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" role="group" aria-labelledby="q-{{ $key }}-label">
                @foreach (GithubMonitor::CHECKS as $value => $check)
                    <label class="group flex cursor-pointer items-start gap-2.5 rounded-lg border border-ink-700 bg-ink-850 p-3.5 hover:border-ink-400
                                  has-checked:border-accent has-checked:bg-accent/10 has-focus-visible:outline-2 has-focus-visible:outline-accent">
                        <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" @checked(in_array($value, (array) $current, true)) class="mt-0.5 size-4 shrink-0 accent-accent">
                        <span>
                            <span class="block text-sm font-semibold text-ink-200 group-has-checked:text-amber-200">{{ $check['label'] }}</span>
                            <span class="mt-0.5 block text-xs text-ink-400">{{ $check['desc'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @break
    @endswitch

    @error("answers.{$key}")
        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
    @enderror
</section>
