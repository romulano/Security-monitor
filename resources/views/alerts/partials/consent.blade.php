@php
    $hasDomainVisible = ($questions->firstWhere('model.key', 'has_domain')['value'] ?? null) === 'sim';
    $consented = (bool) old('consent', $domain?->consent_at !== null);
    $currentTerm = (string) \App\Support\Settings::get('alerts.consent_version');
    $outdated = $domain?->consent_at && $domain->consent_version !== $currentTerm;
    $homeOnly = $outdated && in_array($domain->consent_version, \App\Models\MonitoredDomain::HOME_ONLY_CONSENTS, true);
    $noBrowser = $outdated && in_array($domain->consent_version, \App\Models\MonitoredDomain::NO_BROWSER_CONSENTS, true);
@endphp

<section data-show-if="{{ json_encode(['key' => 'has_domain', 'in' => ['sim']]) }}" @unless ($hasDomainVisible) hidden @endunless
         class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h3 class="text-[15px] font-semibold text-white">Autoriza a identificação automática?</h3>
        <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] tracking-wider text-ink-400 uppercase">consentimento</span>
    </div>

    @if ($outdated)
        <p class="rounded-lg border border-amber-800 bg-amber-950/30 px-4 py-3 text-sm text-amber-200">
            Seu aceite é do termo {{ $domain->consent_version }}{{ $homeOnly ? ', que cobre só a página inicial' : '' }}. O termo atual é o
            {{ $currentTerm }}{{ $homeOnly ? ' e inclui também a página de erro (um endereço que não existe) e o blog que a página inicial linkar' : '' }}{{ $noBrowser ? ($homeOnly ? ', além dos' : ' e inclui também os') .' prints de até 3 páginas públicas do site, para o Radar de domínios comparar com sites que imitam o seu' : '' }}.
            Mantenha marcado e salve para aceitar o {{ $currentTerm }}; desmarque para revogar.
        </p>
    @endif

    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5">
        <input type="checkbox" name="consent" value="1" @checked($consented) class="mt-0.5 size-5 shrink-0 accent-accent">
        <span class="text-sm text-white">
            Autorizo o Security Monitor a consultar, de forma passiva, os registros DNS públicos, a página inicial, a página de erro e o blog de
            <b class="font-mono">{{ $domain?->domain ?? 'meu domínio' }}</b>, a adicionar ao meu perfil as tecnologias que reconhecer e a guardar prints
            de até 3 páginas públicas do site para o Radar de domínios.
        </span>
    </label>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-ink-800 p-4">
            <p class="mb-2 text-[11px] tracking-widest text-emerald-300 uppercase">O que fazemos</p>
            <ul class="list-disc space-y-1 pl-5 text-sm text-ink-200">
                <li>Lemos o DNS público: MX, SPF, TXT, NS e CNAME</li>
                <li>Até 3 páginas, como um navegador: a inicial, um endereço que não existe (a página de erro mostra o servidor por trás do cache) e o blog, se a inicial apontar para ele</li>
                <li>No máximo {{ config('secmon.alerts.discovery.max_requests', 5) }} requisições ao site por vez, nunca a mesma página duas vezes</li>
                <li>Olhamos cabeçalhos, cookies, marcas no HTML e scripts de terceiros; o que roda sobre outra tecnologia a revela (Laravel → PHP)</li>
                <li>Colocamos no perfil tudo o que identificarmos, para monitorar</li>
                <li>Para o Radar de domínios: abrimos até 3 páginas públicas (a inicial e duas linkadas nela) num navegador, como computador, Android e iPhone, e guardamos os prints para comparar com sites que imitam o seu</li>
                <li>Repetimos 1 vez por mês enquanto autorizado</li>
            </ul>
        </div>
        <div class="rounded-lg border border-ink-800 p-4">
            <p class="mb-2 text-[11px] tracking-widest text-red-300 uppercase">O que nunca fazemos</p>
            <ul class="list-disc space-y-1 pl-5 text-sm text-ink-200">
                <li>Scan de portas ou de vulnerabilidades</li>
                <li>Login, preencher ou enviar formulários, ou páginas que exigem conta</li>
                <li>Qualquer teste que altere o seu sistema</li>
                <li>Compartilhar o resultado com terceiros</li>
            </ul>
        </div>
    </div>

    <p class="text-xs text-ink-400">
        Termo {{ \App\Support\Settings::get('alerts.consent_version') }}. Guardamos data, hora e IP do aceite. O que entrar no
        perfil pode ser removido a qualquer momento. Desmarcar revoga a autorização e apaga os achados.
    </p>
</section>
