{{--
    O setor de um monitoramento novo da empresa (DESENHO-5.2 §2.1): quem administra a empresa pode deixar "sem setor" (toda a
    empresa vê); quem responde por setores escolhe entre os dele. O servidor confere de novo (Visibility::stampFor).
    Parâmetros: $sectors, $sectorless, $what (o que o setor vê, p.ex. "o Radar e os domínios achados"); opcionais: $id e
    $name (padrão "setor", para mais de um formulário na página), $hint (sobrescreve o texto de ajuda) e $label (o nome do
    campo; padrão "Setor": a tela que também pergunta o ramo da empresa diz "Setor da empresa que vê este alerta").
--}}
@php
    $id ??= 'setor';
    $name ??= 'setor';
    $label ??= 'Setor';
    $hint ??= 'Quem está no setor vê '.$what.' e recebe os avisos; quem administra a empresa vê tudo.'
        .($sectorless ? ' Sem setor, toda a empresa vê.' : '').' Dá para trocar depois.';
    $only = ! $sectorless && $sectors->count() === 1 ? $sectors->first() : null;
    $invalid = $errors->has($name);
    $described = $id.'-hint'.($invalid ? ' '.$id.'-error' : '');
@endphp
@if ($sectors->isNotEmpty() || $sectorless)
    <section class="space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5">
        @if ($only)
            <p class="text-[15px] font-semibold text-white">{{ $label }}: {{ $only->name }}</p>
            <input type="hidden" name="{{ $name }}" value="{{ $only->id }}">
            <p id="{{ $id }}-hint" class="text-xs text-ink-400">É o único setor pelo qual você responde. {{ $hint }}</p>
        @else
            <label for="{{ $id }}" class="text-[15px] font-semibold text-white">{{ $label }}</label>
            <select id="{{ $id }}" name="{{ $name }}" class="min-h-11 w-full rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent"
                    aria-describedby="{{ $described }}" @if ($invalid) aria-invalid="true" @endif @unless ($sectorless) required @endunless>
                @if ($sectorless)
                    <option value="" @selected((string) old($name) === '')>Sem setor (toda a empresa vê)</option>
                @else
                    <option value="" disabled @selected(old($name) === null)>Escolha o setor…</option>
                @endif
                @foreach ($sectors as $sector)
                    <option value="{{ $sector->id }}" @selected((string) old($name) === (string) $sector->id)>{{ $sector->name }}</option>
                @endforeach
            </select>
            <p id="{{ $id }}-hint" class="text-xs text-ink-400">{{ $hint }}</p>
        @endif
        @error($name)<p id="{{ $id }}-error" class="text-sm text-red-300">{{ $message }}</p>@enderror
    </section>
@endif
