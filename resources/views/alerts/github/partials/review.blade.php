{{-- Passo 6: o resumo das respostas, o que a IA entendeu, as buscas do dia e a ativação. --}}
@php
    use App\Models\GithubFinding;
    use App\Models\GithubMonitor;

    $list = fn (?array $values) => $values ? collect($values) : collect();
    $rows = [
        1 => [['Organizações', $monitor->orgs], ['Repositórios privados', $monitor->private_repos], ['Repositórios públicos oficiais', $monitor->public_repos]],
        2 => [['Nomes de chaves', $monitor->key_names], ['Prefixos de token', $monitor->token_prefixes], ['Endereços internos', $monitor->hostnames], ['Domínios de e-mail', $monitor->email_domains]],
        3 => [['Marca e produtos', $monitor->brands], ['O que vigiar', collect($monitor->checks ?? [])->map(fn ($c) => GithubMonitor::CHECKS[$c]['label'] ?? $c)->all()]],
    ];
    $kinds = GithubFinding::KINDS;
@endphp

<div class="space-y-4">
    @foreach ($rows as $n => $items)
        <section class="rounded-xl border border-ink-800 bg-ink-900 p-5">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="font-semibold text-white">{{ $steps[$n][0] }}</h3>
                <a href="{{ route('alerts.github.interview', $monitor->urlParams(['passo' => $n])) }}" class="text-sm text-accent hover:underline">Editar</a>
            </div>
            <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                @foreach ($items as [$label, $values])
                    <div>
                        <dt class="text-[11px] tracking-widest text-ink-400 uppercase">{{ $label }}</dt>
                        <dd class="mt-1 flex flex-wrap gap-1 text-sm">
                            @forelse ($list($values) as $value)
                                <span class="rounded bg-ink-800 px-2 py-0.5 {{ $label === 'O que vigiar' ? '' : 'font-mono' }} text-xs text-white">{{ $value }}</span>
                            @empty
                                <span class="text-ink-400">—</span>
                            @endforelse
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endforeach

    <section class="rounded-xl border border-ink-800 bg-ink-900 p-5">
        <div class="flex items-baseline justify-between gap-3">
            <h3 class="font-semibold text-white">Adicional</h3>
            <a href="{{ route('alerts.github.interview', $monitor->urlParams(['passo' => 4])) }}" class="text-sm text-accent hover:underline">Editar</a>
        </div>
        @if ($monitor->extra_text)
            <p class="mt-2 text-sm whitespace-pre-line text-ink-200">{{ $monitor->extra_text }}</p>
            <div class="mt-3">@include('alerts.github.partials.extra-status')</div>
        @else
            <p class="mt-2 text-sm text-ink-400">Sem texto: nada vai para a IA nesta etapa.</p>
        @endif
    </section>

    <section class="rounded-xl border border-ink-800 bg-ink-900 p-5">
        <div class="flex items-baseline justify-between gap-3">
            <h3 class="font-semibold text-white">Aviso a partir de: {{ $monitor->levelLabel() }}</h3>
            <a href="{{ route('alerts.github.interview', $monitor->urlParams(['passo' => 5])) }}" class="text-sm text-accent hover:underline">Editar</a>
        </div>
        <p class="mt-1 flex flex-wrap gap-1">
            @foreach ($monitor->levelsSent() as $level)
                <x-criticity :level="$level" size="xs" />
            @endforeach
        </p>
        <p class="mt-2 text-sm text-ink-400">Esses níveis passam para a segunda análise da IA. O resto fica na página do GitHub, em “Abaixo do seu nível”.</p>
    </section>

    <section class="rounded-xl border border-ink-800 bg-ink-900 p-5">
        <h3 class="font-semibold text-white">O que vamos buscar, uma vez por dia</h3>
        <p class="mt-1 text-sm text-ink-400">
            {{ $plan['total'] }} {{ $plan['total'] === 1 ? 'busca' : 'buscas' }} na API oficial do GitHub, {{ $plan['code'] }} delas em código.
            @if ($plan['over'])
                <span class="text-amber-200">Passa do limite de {{ config('secmon.github.max_code_queries') }} buscas em código por dia: as últimas ficam de fora. Tire os termos menos importantes.</span>
            @endif
        </p>
        @unless ($codeSearch)
            <p class="mt-2 rounded-lg border border-amber-800/70 bg-amber-950/30 px-3 py-2 text-sm text-amber-100">
                A busca em código está indisponível no momento: por enquanto rodam só as buscas de repositórios. Avisamos quando ela voltar.
            </p>
        @endunless
        <ul class="mt-3 space-y-1.5 text-sm">
            @foreach ($plan['groups'] as $kind => $terms)
                <li class="flex flex-wrap gap-x-2 gap-y-1">
                    <span class="text-ink-200">{{ $kinds[$kind] ?? $kind }}:</span>
                    @foreach (array_slice($terms, 0, 8) as $term)
                        <span class="rounded bg-ink-800 px-1.5 font-mono text-xs leading-5 text-white">{{ $term }}</span>
                    @endforeach
                    @if (count($terms) > 8)
                        <span class="text-xs text-ink-400">+{{ count($terms) - 8 }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <details class="rounded-xl border border-ink-800 bg-ink-900 p-5">
        <summary class="cursor-pointer text-sm font-semibold text-white">Como cada achado é julgado</summary>
        <div class="mt-4">@include('alerts.github.partials.levels')</div>
    </details>

    <form method="POST" action="{{ route('alerts.github.interview.finish', $monitor->urlParams()) }}" class="flex flex-wrap items-center justify-between gap-3 pt-2">
        @csrf
        <a href="{{ route('alerts.github.interview', $monitor->urlParams(['passo' => 5])) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Voltar</a>
        <button class="inline-flex min-h-11 items-center rounded-lg bg-accent px-5 text-sm font-semibold text-ink-950 hover:brightness-110">
            {{ $monitor->isCompleted() ? 'Salvar alterações' : 'Ativar monitoramento' }}
        </button>
    </form>
</div>
