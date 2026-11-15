@extends('layouts.app')

@section('title', 'Radar de domínios · Security Monitor')

@php
    use App\Models\LookalikeWatch;
    use App\Models\MonitoredDomain;

    $last = count($steps);
    $reached = $watch?->step_reached ?? 1;
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $at = fn (?int $n) => array_filter(['passo' => $n, 'radar' => $watch?->id]);
@endphp

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            @include('alerts.partials.crumbs', ['parent' => ['Radar de domínios', route('alerts.lookalikes')], 'here' => 'Configurar o Radar'])
            <h1 class="text-xl font-semibold text-white">
                Radar de domínios @if ($domain) · <span class="font-mono text-lg">{{ $domain->domain }}</span> @endif
            </h1>
            <p class="text-sm text-ink-400">
                Passo {{ $step }} de {{ $last }}
                @if ($step === 1 && ! $watch) · uns 2 minutos · separado da entrevista de Notícias @endif
            </p>
        </div>
        @if ($watch && ! $watch->isCompleted() && $watch->updated_at)
            <span class="text-xs text-ink-400">Rascunho salvo às {{ $watch->updated_at->timezone('America/Sao_Paulo')->format('H:i') }}</span>
        @endif
    </div>

    <div class="mt-4 h-1 overflow-hidden rounded bg-ink-850" aria-hidden="true">
        <div class="h-1 bg-accent" style="width: {{ round($step / $last * 100) }}%"></div>
    </div>

    <div class="mt-6 flex flex-wrap items-start gap-6">
        <nav aria-label="Passos da entrevista" class="flex flex-[1_1_220px] flex-col gap-0.5">
            @foreach ($steps as $n => [$title, , $sub])
                @php
                    $current = $n === $step;
                    $done = ! $current && ($n < $reached || $watch?->isCompleted());
                    $open = $n <= $reached;
                @endphp
                @if ($open)
                    <a href="{{ route('alerts.lookalikes.interview', $at($n)) }}" @if ($current) aria-current="step" @endif
                       class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 {{ $current ? 'bg-ink-850' : 'hover:bg-ink-900' }}">
                @else
                    <span class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 opacity-70">
                @endif
                        <span class="grid size-6 shrink-0 place-items-center rounded-full border text-xs font-semibold
                                     {{ $current ? 'border-accent bg-accent text-ink-950' : ($done ? 'border-emerald-700 bg-emerald-950 text-emerald-300' : 'border-ink-700 text-ink-400') }}">{{ $n }}</span>
                        <span class="flex flex-col leading-tight">
                            <span class="text-sm font-semibold {{ $current ? 'text-white' : 'text-ink-200' }}">{{ $title }}</span>
                            <span class="text-xs text-ink-400">{{ $current ? 'agora' : ($done ? 'concluído' : $sub) }}</span>
                        </span>
                @if ($open)
                    </a>
                @else
                    </span>
                @endif
            @endforeach
        </nav>

        <div class="min-w-0 flex-[999_1_560px] space-y-4">
            @if ($step === 1 && ! $watch)
                <div class="rounded-xl border border-accent/40 bg-accent/5 px-5 py-4">
                    <p class="font-semibold text-white">Nomes parecidos com o seu domínio, vigiados todo dia.</p>
                    <p class="mt-1 text-sm text-ink-200">
                        O Radar confere milhares de variações no DNS e nos certificados públicos, abre as que existem no computador, no Android e
                        no iPhone, e a IA dá o parecer antes de avisar. Só vigiamos um domínio que é seu: a posse é comprovada no passo 3.
                    </p>
                </div>
            @endif

            <div>
                <h2 class="text-lg font-semibold text-white">{{ $step === 1 ? 'Qual domínio vigiar?' : ($step === 2 ? 'Como a marca aparece' : 'Comprove que o domínio é seu e ative') }}</h2>
                <p class="text-sm text-ink-400">{{ $text[1] }}</p>
            </div>

            @if ($step === 1)
                @if ($watch?->isCompleted())
                    <section class="space-y-1 rounded-xl border border-ink-800 bg-ink-900 p-5">
                        <p class="font-mono text-[15px] text-white">{{ $domain->domain }}</p>
                        <p class="text-sm text-ink-400">O domínio de um Radar ativado não muda. Para vigiar outro domínio, use “Adicionar domínio” na página do Radar ou em Integração.</p>
                    </section>
                @else
                    @php
                        $currentProven = $domain ? $proven->firstWhere('domain', $domain->domain) : null;
                        $choice = old('origem', match (true) {
                            (bool) $currentProven && $domain->isVerified() => (string) $currentProven->id,
                            (bool) $domain => 'outro',
                            $proven->isNotEmpty() => (string) $proven->first()->id,
                            default => 'outro',
                        });
                    @endphp
                    <form id="step-form" method="POST" action="{{ route('alerts.lookalikes.interview.update', $at(1)) }}" class="space-y-4">
                        @csrf
                        @method('PUT')

                        @if ($proven->isNotEmpty())
                            <fieldset class="space-y-2.5 rounded-xl border border-ink-800 bg-ink-900 p-5">
                                <legend class="px-1.5 text-[11px] tracking-widest text-ink-400 uppercase">Já comprovados no Security Monitor</legend>
                                @foreach ($proven as $option)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5">
                                        <input type="radio" name="origem" value="{{ $option->id }}" @checked($choice === (string) $option->id) class="mt-0.5 size-5 shrink-0 accent-accent">
                                        <span class="flex flex-col gap-0.5">
                                            <span class="font-mono text-[15px] text-white">{{ $option->domain }}</span>
                                            <span class="text-sm text-ink-400">
                                                Posse comprovada em {{ $option->verified_at->timezone('America/Sao_Paulo')->format('d/m') }}
                                                {{ $option->origin }}. Usando este, o passo 3 já vem pronto.
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5">
                                    <input type="radio" name="origem" value="outro" @checked($choice === 'outro') class="mt-0.5 size-5 shrink-0 accent-accent">
                                    <span class="flex flex-1 flex-col gap-2">
                                        <span class="text-[15px] text-white">Outro domínio</span>
                                        <input type="text" name="outro" aria-label="Outro domínio" value="{{ old('outro', $currentProven ? '' : $domain?->domain) }}" placeholder="empresa.com.br"
                                               autocomplete="off" spellcheck="false" maxlength="255"
                                               class="min-h-11 rounded-lg border border-ink-700 bg-ink-950 px-3 font-mono text-sm text-white placeholder:text-ink-400 focus:border-accent">
                                        <span class="text-xs text-ink-400">Sem “www” nem “https://”. Um domínio interno ou um IP não servem.</span>
                                    </span>
                                </label>
                            </fieldset>
                        @else
                            <section class="space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5">
                                <input type="hidden" name="origem" value="outro">
                                <label for="outro" class="text-[15px] font-semibold text-white">Domínio</label>
                                <input id="outro" type="text" name="outro" value="{{ old('outro', $domain?->domain) }}" placeholder="empresa.com.br"
                                       autocomplete="off" spellcheck="false" maxlength="255"
                                       class="min-h-11 w-full rounded-lg border border-ink-700 bg-ink-850 px-3 font-mono text-sm text-white placeholder:text-ink-400 focus:border-accent">
                                <p class="text-xs text-ink-400">Sem “www” nem “https://”. Um domínio interno ou um IP não servem.</p>
                            </section>
                        @endif
                        @error('origem')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                        @error('outro')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                    </form>
                @endif
            @elseif ($step === 2)
                {{-- data-interview liga o JS dos nomes em chips: sem ele, o Enter envia o formulário e pula para o passo 3. --}}
                <form id="step-form" method="POST" action="{{ route('alerts.lookalikes.interview.update', $at(2)) }}" class="space-y-4" data-interview>
                    @csrf
                    @method('PUT')
                    <section class="space-y-5 rounded-xl border border-ink-800 bg-ink-900 p-5">
                        <div class="space-y-1.5">
                            <label for="empresa" class="text-[15px] font-semibold text-white">Nome da empresa</label>
                            <input id="empresa" type="text" name="empresa" value="{{ old('empresa', $watch->company_name) }}" maxlength="120" required
                                   placeholder="Ex.: Acme Pagamentos S.A."
                                   class="min-h-11 w-full rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent">
                            <p class="text-xs text-ink-400">Como aparece no kit de denúncia e para a IA. Escreva o nome de quem este domínio representa, que pode ser outra empresa.</p>
                            @error('empresa')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1.5">
                            <label for="marcas" class="text-[15px] font-semibold text-white">Nomes da marca e dos produtos</label>
                            <div data-tags data-name="marcas[]"
                                 class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-850 p-2 focus-within:border-accent">
                                @foreach ((array) old('marcas', $watch->brandNames()) as $item)
                                    @continue(trim((string) $item) === '')
                                    <span data-tag class="inline-flex items-center gap-1 rounded-md bg-ink-800 py-0.5 pr-1 pl-2.5 text-sm text-white">
                                        {{ $item }}
                                        <input type="hidden" name="marcas[]" value="{{ $item }}">
                                        <button type="button" data-tag-remove aria-label="Remover {{ $item }}" class="grid size-7 place-items-center rounded text-ink-400 hover:text-white">&times;</button>
                                    </span>
                                @endforeach
                                <input id="marcas" data-tags-input name="marcas[]" type="text" autocomplete="off" maxlength="60" placeholder="Digite e tecle Enter"
                                       class="min-h-8 min-w-40 flex-1 border-0 bg-transparent px-1 text-sm text-white placeholder:text-ink-400 focus:outline-none">
                            </div>
                            <p class="text-xs text-ink-400">Tecle Enter ou vírgula depois de cada item · até {{ LookalikeWatch::MAX_BRANDS }}. Ex.: o nome do app, de um produto conhecido ou a sigla que os clientes usam.</p>
                            @error('marcas.*')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                        </div>
                    </section>

                    @if ($samples['hosts'])
                        <section class="rounded-xl border border-ink-800 px-5 py-4">
                            <p class="text-[11px] tracking-widest text-ink-400 uppercase">Exemplos do que vamos conferir</p>
                            <p class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($samples['hosts'] as $host)
                                    <span class="rounded-md border border-ink-800 px-2 py-0.5 font-mono text-[13px] text-ink-200">{{ $host }}</span>
                                @endforeach
                            </p>
                            <p class="mt-2 text-xs text-ink-400">Ao todo, {{ number_format($samples['total'], 0, ',', '.') }} variações por varredura, mais os certificados públicos com o nome. Os exemplos usam o que está salvo.</p>
                        </section>
                    @endif
                </form>
            @else
                <x-domain-ownership :state="$ownership" :action="route('alerts.lookalikes.interview.verify', ['radar' => $watch->id])"
                                    :waiting="$watch->isCompleted()" effect="o Radar pausa">
                    {{ $watch->isCompleted() ? 'O Radar começa sozinho assim que o registro aparecer.' : '' }}
                </x-domain-ownership>

                @php
                    $hasValidConsent = $domain->consent_at && ! in_array($domain->consent_version, MonitoredDomain::NO_BROWSER_CONSENTS, true);
                @endphp
                <form id="step-form" method="POST" action="{{ route('alerts.lookalikes.interview.activate', ['radar' => $watch->id]) }}" class="space-y-4">
                    @csrf
                    <section class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <h3 class="text-[15px] font-semibold text-white">Comparar com o seu site</h3>
                            <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] tracking-wider text-ink-400 uppercase">opcional</span>
                        </div>
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5">
                            <input type="checkbox" name="consent" value="1" @checked(old('consent', $hasValidConsent)) class="mt-0.5 size-5 shrink-0 accent-accent">
                            <span class="text-sm text-white">
                                Autorizo o Security Monitor a abrir até 3 páginas públicas de <b class="font-mono">{{ $domain->domain }}</b> (a inicial e duas linkadas nela) num
                                navegador, como computador, Android e iPhone, 1 vez por mês, e a guardar os prints para comparar com sites que imitam o seu.
                            </span>
                        </label>
                        <p class="text-xs text-ink-400">
                            Sem isso o Radar funciona igual, mas a IA não compara a cópia com o original. Nunca fazemos login, formulário ou scan.
                            @if ($hasValidConsent && $domain->consent_version !== MonitoredDomain::RADAR_CONSENT)
                                O aceite do termo {{ $domain->consent_version }}, dado na entrevista de Notícias em {{ $domain->consent_at->timezone('America/Sao_Paulo')->format('d/m') }}, vale aqui.
                            @else
                                Termo do Radar ({{ MonitoredDomain::RADAR_CONSENT }}). Guardamos data, hora e IP do aceite. Desmarcar revoga e apaga os prints do seu site.
                            @endif
                        </p>
                    </section>

                    @unless ($watch->isCompleted())
                        <section class="rounded-xl border border-ink-800 px-5 py-4">
                            <p class="text-[11px] tracking-widest text-ink-400 uppercase">Ao ativar</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-ink-200">
                                <li>Com o TXT no ar, a primeira varredura confere milhares de variações no DNS (leva alguns minutos).</li>
                                <li>Sem o TXT ainda, o Radar fica esperando a comprovação e começa sozinho quando ela aparecer.</li>
                                <li>Avisos de Crítico e Alto passam pelo parecer da IA e chegam nos apps que recebem Radar em <a href="{{ route('alerts.notifiers') }}" class="text-accent hover:underline">Notificadores</a>.</li>
                            </ul>
                        </section>
                    @endunless
                </form>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                @if ($step > 1)
                    <a href="{{ route('alerts.lookalikes.interview', $at($step - 1)) }}" class="{{ $button }} border border-ink-800 text-ink-200 hover:border-ink-700">Voltar</a>
                @else
                    <a href="{{ $watch?->isCompleted() ? route('alerts.lookalikes', $watch->urlParams()) : route('alerts.integrations') }}" class="{{ $button }} border border-ink-800 text-ink-200 hover:border-ink-700">Cancelar</a>
                @endif

                @if ($step === 1 && $watch?->isCompleted())
                    <a href="{{ route('alerts.lookalikes.interview', $at(2)) }}" class="{{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">Continuar</a>
                @else
                    <button form="step-form" class="{{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">
                        {{ $step < $last ? 'Continuar' : ($watch->isCompleted() ? 'Salvar' : 'Ativar o Radar') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
@endsection
