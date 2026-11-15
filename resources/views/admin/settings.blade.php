@extends('layouts.admin')

@section('title', 'Configurações')

@section('content')
    <form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-3xl space-y-8">
        @csrf
        @method('PUT')

        <section class="rounded-lg border border-ink-800 bg-ink-900 p-5">
            <h2 class="mb-1 text-sm font-semibold text-white">Bot de crawler</h2>
            <p class="mb-5 text-xs text-ink-700">
                Roda de hora em hora pelo scheduler. Desligar aqui para tudo sem precisar mexer no container.
            </p>

            <label class="mb-5 flex items-start gap-3">
                <input type="hidden" name="crawler_enabled" value="0">
                <input type="checkbox" name="crawler_enabled" value="1" @checked($values['crawler.enabled'])
                       class="mt-0.5 rounded border-ink-700 bg-ink-850 text-accent">
                <span>
                    <span class="block text-sm text-ink-200">Crawler ligado</span>
                    <span class="block text-xs text-ink-700">Quando desligado, o agendamento horário não coleta nada.</span>
                </span>
            </label>

            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block">
                    <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Itens por fonte</span>
                    <input type="number" name="crawler_max_items" min="1" max="100" value="{{ old('crawler_max_items', $values['crawler.max_items_per_source']) }}"
                           class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Timeout (s)</span>
                    <input type="number" name="crawler_timeout" min="5" max="120" value="{{ old('crawler_timeout', $values['crawler.timeout']) }}"
                           class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Limiar de dedup</span>
                    <input type="number" step="0.01" name="crawler_dedup_threshold" min="0.3" max="0.95"
                           value="{{ old('crawler_dedup_threshold', $values['crawler.dedup_threshold']) }}"
                           class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm">
                </label>
            </div>

            <p class="mt-2 text-xs text-ink-700">
                Limiar alto = mais matérias separadas; limiar baixo = mais fontes agrupadas no mesmo caso.
            </p>

            <label class="mt-4 block">
                <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">User-Agent</span>
                <input type="text" name="crawler_user_agent" value="{{ old('crawler_user_agent', $values['crawler.user_agent']) }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-sm">
            </label>
        </section>

        <section class="rounded-lg border border-ink-800 bg-ink-900 p-5">
            <h2 class="mb-1 text-sm font-semibold text-white">Bot de análise (IA)</h2>
            <p class="mb-5 text-xs text-ink-700">
                Gera o resumo em markdown, a criticidade e os indicadores. Sem IA, a extração cai para a heurística
                (regex de CVE/IOC/TTP + dicionários) e o feed continua funcionando.
            </p>

            <label class="mb-5 flex items-start gap-3">
                <input type="hidden" name="enricher_enabled" value="0">
                <input type="checkbox" name="enricher_enabled" value="1" @checked($values['enricher.enabled'])
                       class="mt-0.5 rounded border-ink-700 bg-ink-850 text-accent">
                <span>
                    <span class="block text-sm text-ink-200">Enriquecimento por IA ligado</span>
                    <span class="block text-xs text-ink-700">Desligado, usa apenas a extração heurística (custo zero).</span>
                </span>
            </label>

            <fieldset class="mb-5">
                <legend class="mb-2 text-xs tracking-widest text-ink-700 uppercase">Driver</legend>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($drivers as $driver)
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3
                                      {{ $values['enricher.driver'] === $driver['name'] ? 'border-accent bg-accent/5' : 'border-ink-800' }}">
                            <input type="radio" name="enricher_driver" value="{{ $driver['name'] }}"
                                   @checked($values['enricher.driver'] === $driver['name'])
                                   class="mt-0.5 border-ink-700 bg-ink-850 text-accent">
                            <span>
                                <span class="block text-sm capitalize text-ink-200">{{ ['openai' => 'OpenAI', 'openrouter' => 'OpenRouter'][$driver['name']] ?? $driver['name'] }}</span>
                                <span class="block text-[11px] {{ $driver['ready'] ? 'text-emerald-400' : 'text-red-400' }}">
                                    {{ $driver['note'] }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-[2fr_1fr_2fr]">
                @include('admin._model-select', [
                    'label' => 'Modelo Claude',
                    'name' => 'enricher_claude_model',
                    'catalog' => $models['claude'],
                    'value' => (string) old('enricher_claude_model', $values['enricher.claude_model']),
                ])

                <label class="block">
                    <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Esforço Claude</span>
                    <select name="enricher_claude_effort" class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm">
                        <option value="">o do modelo</option>
                        @foreach ($claudeEfforts as $effort)
                            <option value="{{ $effort }}" @selected(old('enricher_claude_effort', $values['enricher.claude_effort']) === $effort)>{{ $effort }}</option>
                        @endforeach
                    </select>
                </label>

                @include('admin._model-select', [
                    'label' => 'Modelo OpenAI',
                    'name' => 'enricher_openai_model',
                    'catalog' => $models['openai'],
                    'value' => (string) old('enricher_openai_model', $values['enricher.openai_model']),
                ])
            </div>

            <div class="mt-4">
                @include('admin._model-select', [
                    'label' => 'Modelo OpenRouter',
                    'name' => 'enricher_openrouter_model',
                    'catalog' => $models['openrouter'],
                    'value' => (string) old('enricher_openrouter_model', $values['enricher.openrouter_model']),
                ])
            </div>

            <div class="mt-6 rounded-lg border border-ink-800 p-4">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-white">Hermes — Claude Code da máquina</h3>
                        <p class="text-xs text-ink-700">
                            Ponte local em <code class="font-mono">~/job/hermes</code>: a análise roda no
                            <code class="font-mono">claude</code> do computador, sem chave de API.
                        </p>
                    </div>
                    <span class="rounded-full border px-2 py-0.5 text-[11px] {{ $hermes['online'] ? 'border-emerald-400/40 text-emerald-400' : 'border-red-400/40 text-red-400' }}">
                        {{ $hermes['online'] ? 'no ar' : 'fora do ar' }}
                    </span>
                </div>

                @if ($hermes['online'])
                    <p class="mb-4 font-mono text-xs text-ink-400">
                        padrão {{ $hermes['modelo'] ?? '?' }} · esforço {{ $hermes['esforco'] ?? '?' }}
                        · {{ $hermes['chamadas'] ?? 0 }} chamadas ({{ $hermes['falhas'] ?? 0 }} falhas)
                        · US$ {{ number_format((float) ($hermes['custo_usd'] ?? 0), 4, ',', '.') }}
                        · token {{ $hermes['token'] }}
                    </p>
                @else
                    <p class="mb-4 text-xs text-red-400">
                        {{ \Illuminate\Support\Str::limit($hermes['error'] ?? 'sem resposta', 160) }}
                        <span class="block text-ink-700">
                            Suba com <code class="font-mono">cd ~/job/hermes &amp;&amp; ./iniciar.sh fundo</code>.
                        </span>
                    </p>
                @endif

                <div class="grid gap-4 sm:grid-cols-[2fr_1fr_1fr]">
                    <label class="block">
                        <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Endereço</span>
                        <input type="url" name="enricher_hermes_url" value="{{ old('enricher_hermes_url', $values['enricher.hermes_url']) }}"
                               class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-sm">
                    </label>

                    @include('admin._model-select', [
                        'label' => 'Modelo',
                        'name' => 'enricher_hermes_model',
                        'catalog' => $models['hermes'],
                        'value' => (string) old('enricher_hermes_model', $values['enricher.hermes_model']),
                    ])

                    <label class="block">
                        <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Esforço</span>
                        <select name="enricher_hermes_effort" class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm">
                            <option value="">o do hermes</option>
                            @foreach ($efforts as $effort)
                                <option value="{{ $effort }}" @selected(old('enricher_hermes_effort', $values['enricher.hermes_effort']) === $effort)>{{ $effort }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <p class="mt-2 text-xs text-ink-700">
                    O alias acompanha a versão mais nova da família; o nome completo fixa a versão.
                    <code class="font-mono">low</code> basta para extração estruturada. O token é o <code class="font-mono">HERMES_TOKEN</code> do
                    <code class="font-mono">.env</code>
                    ({{ $hermesTokenSet ? 'definido' : 'não definido' }}), igual ao do <code class="font-mono">hermes.conf</code>.
                </p>
            </div>

            <p class="mt-3 text-xs text-ink-700">
                As chaves (<code class="font-mono">ANTHROPIC_API_KEY</code>, <code class="font-mono">OPENAI_API_KEY</code>,
                <code class="font-mono">HERMES_TOKEN</code>) ficam só no <code class="font-mono">.env</code> — não são
                editáveis por aqui de propósito.
            </p>
        </section>

        <section class="rounded-lg border border-ink-800 bg-ink-900 p-5">
            <h2 class="mb-1 text-sm font-semibold text-white">Buscadores</h2>
            <p class="mb-5 text-xs text-ink-700">
                O <a href="{{ route('sitemap') }}" target="_blank" rel="noopener" class="font-mono text-accent hover:underline">/sitemap.xml</a>
                tem {{ number_format($sitemapUrls, 0, ',', '.') }} endereço(s), as páginas fixas e as matérias publicadas, e é refeito
                quando uma matéria entra, muda ou sai do ar. O robots.txt aponta para ele, e o Google o lê sozinho.
            </p>

            <label class="mb-4 flex items-start gap-3">
                <input type="hidden" name="indexnow_enabled" value="0">
                <input type="checkbox" name="indexnow_enabled" value="1" @checked($values['indexnow.enabled'])
                       class="mt-0.5 rounded border-ink-700 bg-ink-850 text-accent">
                <span>
                    <span class="block text-sm text-ink-200">Avisar os buscadores na hora (IndexNow)</span>
                    <span class="block text-xs text-ink-700">
                        Bing, Yandex, Seznam, Naver e Yep (a busca do ChatGPT usa o índice do Bing) recebem a matéria nova, a que volta
                        ou sai do feed e as da fusão de matérias, num aviso só por rodada da coleta. O Google não usa o IndexNow.
                    </span>
                </span>
            </label>

            @php $last = $indexNow['last']; @endphp
            <p class="text-xs text-ink-400">
                @if ($last)
                    Último aviso em {{ \Illuminate\Support\Carbon::parse($last['at'])->timezone('America/Sao_Paulo')->format('d/m H:i') }}:
                    {{ $last['urls'] }} endereço(s),
                    @if ($last['error'] === null)
                        <span class="text-emerald-300">aceito (HTTP {{ $last['status'] }})</span>.
                    @else
                        <span class="text-red-300">{{ $last['status'] ? 'HTTP '.$last['status'].': ' : '' }}{{ $last['error'] }}</span>.
                    @endif
                @else
                    Nenhum aviso ainda.
                @endif
                {{ $indexNow['pending'] }} endereço(s) na fila.
                @unless ($indexNow['host'])
                    <span class="block text-amber-300">O APP_URL não é um endereço público (https, com domínio): mesmo ligado, o aviso não sai.</span>
                @endunless
                <span class="block text-ink-700">
                    A chave é pública por desenho: o buscador confere em
                    <a href="{{ $indexNow['key_file'] }}" target="_blank" rel="noopener" class="font-mono hover:text-ink-200">{{ $indexNow['key_file'] }}</a>.
                </span>
            </p>
        </section>

        <button type="submit" class="rounded-lg bg-accent px-5 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
            Salvar configurações
        </button>
    </form>
@endsection
