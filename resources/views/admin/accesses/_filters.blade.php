{{-- Os filtros de Admin → Acessos (Visão geral, Robôs, Origens e Ataques) numa linha: o período e o "Quem" (só na Visão geral)
     como links, os campos da aba ($fields) em "Mais filtros", e os filtros ativos em chips com ×. Os links tiram o
     page e os valores padrão; o formulário volta para a aba aberta ($route). --}}
@php
    $fields ??= [];
    // Ataques não tem quem, tipo, dono nem página; as outras abas não têm categoria nem varredura.
    $filters += ['robos' => 'com', 'tipo' => '', 'dono' => '', 'pagina' => '', 'categoria' => '', 'varredura' => ''];
    $quem ??= false;
    $hiddenOurs ??= 0;
    $defaults = ['periodo' => '24h', 'robos' => 'com', 'nossos' => 'esconder'];
    $clean = fn (array $values) => array_filter($values, fn ($value, $key) => $value !== '' && $value !== null && ($defaults[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
    $current = $clean($filters);
    $link = fn (array $change) => route($route, $clean(array_merge($current, $change)));
    $toggle = 'rounded-md px-2.5 py-1 text-xs whitespace-nowrap';
    $on = $toggle.' bg-ink-800 text-white';
    $off = $toggle.' text-ink-400 hover:text-ink-200';
    $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent';
    $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
    $has = fn (string $field) => in_array($field, $fields, true);
    // Pedir o robô nosso já mostra os nossos (AccessReport::context): aí o chip dos nossos não teria o que tirar.
    $forcedOurs = $filters['tipo'] === 'ours' || $filters['dono'] === \App\Services\Admin\AccessReport::OURS;
    $robotChips = $route !== 'admin.accesses.origins';
    $chips = array_filter([
        'ip' => $has('ip') && $filters['ip'] !== '' ? 'IP '.$filters['ip'] : null,
        'pais' => $has('pais') && $filters['pais'] !== '' ? 'País '.$filters['pais'] : null,
        'pagina' => $has('pagina') && $filters['pagina'] !== '' ? 'Página '.$filters['pagina'] : null,
        'tipo' => $robotChips && $filters['tipo'] !== '' ? 'Tipo '.\App\Support\RobotCatalog::label($filters['tipo']) : null,
        'dono' => $robotChips && $filters['dono'] !== '' ? 'Dono '.$filters['dono'] : null,
        'categoria' => $has('categoria') && $filters['categoria'] !== ''
            ? 'Categoria '.($filters['categoria'] === 'recusado' ? 'só recusado' : \App\Support\AttackSignatures::label($filters['categoria'])) : null,
        'varredura' => $filters['varredura'] === '1' ? 'Só varredura' : null,
        'nossos' => $has('nossos') && ! $forcedOurs ? (['mostrar' => 'Nossos à mostra', 'so' => 'Só os nossos'][$filters['nossos']] ?? null) : null,
    ]);
    $open = collect($fields)->contains(fn ($field) => $errors->has($field));
@endphp
<div class="mb-6 space-y-2">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div class="flex items-center gap-1" role="group" aria-label="Período">
            <span class="mr-1 text-xs text-ink-700">Período</span>
            @foreach (['24h' => '24 h', '7d' => '7 dias', '30d' => '30 dias'] as $value => $name)
                <a href="{{ $link(['periodo' => $value]) }}" class="{{ $filters['periodo'] === $value ? $on : $off }}" @if ($filters['periodo'] === $value) aria-current="true" @endif>{{ $name }}</a>
            @endforeach
        </div>
        @if ($quem)
            <div class="flex items-center gap-1" role="group" aria-label="Quem">
                <span class="mr-1 text-xs text-ink-700">Quem</span>
                {{-- Pessoa não tem tipo nem dono de robô: "Pessoas" tira os dois, senão a aba zera. --}}
                @foreach (['com' => ['Todos', []], 'sem' => ['Pessoas', ['tipo' => null, 'dono' => null]], 'so' => ['Robôs', []]] as $value => [$name, $drop])
                    <a href="{{ $link(['robos' => $value] + $drop) }}" class="{{ $filters['robos'] === $value ? $on : $off }}" @if ($filters['robos'] === $value) aria-current="true" @endif>{{ $name }}</a>
                @endforeach
            </div>
        @endif
        <details class="open:basis-full" @if ($open) open @endif>
            <summary class="cursor-pointer text-xs text-ink-400 hover:text-ink-200">Mais filtros</summary>
            <form method="GET" action="{{ route($route) }}" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                {{-- O que a barra já escolheu, e os filtros comuns que a aba não mostra, vão junto. --}}
                @foreach (array_diff_key($current, array_flip($fields)) as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                @if ($has('categoria'))
                    <label class="block">
                        <span class="{{ $label }}">Categoria</span>
                        <select name="categoria" class="{{ $input }}">
                            <option value="">Todas</option>
                            <option value="recusado" @selected($filters['categoria'] === 'recusado')>Só recusado (sem categoria)</option>
                            @foreach (\App\Support\AttackSignatures::CATEGORIES as $value => $name)
                                <option value="{{ $value }}" @selected($filters['categoria'] === $value)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                @if ($has('ip'))
                    <label class="block">
                        <span class="{{ $label }}">IP</span>
                        <input name="ip" value="{{ $filters['ip'] }}" maxlength="45" class="{{ $input }} font-mono">
                        @error('ip')<span class="mt-1 block text-xs text-red-300">{{ $message }}</span>@enderror
                    </label>
                @endif
                @if ($has('pais'))
                    <label class="block">
                        <span class="{{ $label }}">País</span>
                        <input name="pais" value="{{ $filters['pais'] }}" maxlength="2" placeholder="BR" class="{{ $input }} font-mono uppercase">
                    </label>
                @endif
                @if ($has('pagina'))
                    <label class="block">
                        <span class="{{ $label }}">Página</span>
                        <input name="pagina" value="{{ $filters['pagina'] }}" maxlength="200" placeholder="/materia/*" title="A página exata; com * no fim, as que começam assim"
                               class="{{ $input }} font-mono">
                    </label>
                @endif
                @if ($has('nossos'))
                    <label class="block">
                        <span class="{{ $label }}">Nossos</span>
                        <select name="nossos" class="{{ $input }}">
                            @foreach (['esconder' => 'Esconder', 'mostrar' => 'Mostrar', 'so' => 'Só os nossos'] as $value => $name)
                                <option value="{{ $value }}" @selected($filters['nossos'] === $value)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <div><button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">Filtrar</button></div>
            </form>
        </details>
    </div>
    @if ($chips || $hiddenOurs > 0)
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @foreach ($chips as $key => $text)
                <span class="inline-flex max-w-full items-center gap-1 rounded-full border border-ink-800 bg-ink-900 py-0.5 pr-1 pl-2.5 text-ink-200">
                    <span class="truncate font-mono">{{ $text }}</span>
                    <a href="{{ $link([$key => null]) }}" class="rounded-full px-1.5 text-ink-400 hover:text-white" aria-label="Tirar o filtro {{ $text }}">×</a>
                </span>
            @endforeach
            @if ($chips)
                <a href="{{ route($route, $clean(['periodo' => $filters['periodo'], 'robos' => $quem ? $filters['robos'] : null])) }}" class="px-1 text-ink-400 hover:text-ink-200">Limpar</a>
            @endif
            @if ($hiddenOurs > 0)
                <span class="text-ink-700">· {{ number_format($hiddenOurs, 0, ',', '.') }} nosso(s) escondido(s) ·
                    <a href="{{ $link(['nossos' => 'mostrar']) }}" class="text-accent hover:underline">mostrar</a></span>
            @endif
        </div>
    @endif
</div>
