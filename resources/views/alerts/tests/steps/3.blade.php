{{-- Passo 3 (Autorizar-3): o que entra no teste, o que fica de fora sozinho e os nomes que a pessoa exclui. --}}
@php
    $box = 'space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5';
    $label = 'text-[11px] tracking-widest text-ink-400 uppercase';
@endphp

<div>
    <h2 class="text-lg font-semibold text-white">O que entra no teste</h2>
    <p class="text-sm text-ink-400">A regra vale para esta autorização. A lista exata de alvos aparece na página do teste, com o motivo de cada um que ficou de fora.</p>
</div>

<section class="{{ $box }}">
    <h3 class="{{ $label }}">Entram</h3>
    <p class="text-sm text-white"><span class="font-mono">{{ \App\Services\Pentest\PentestRunView::name($domain->host) }}</span> e os subdomínios dele <span class="{{ \App\Services\Pentest\PentestRunView::NAME_CLASS }}">(<span class="font-mono">*.{{ $domain->host }}</span>)</span> que forem seus.</p>
</section>

<section class="{{ $box }}">
    <h3 class="{{ $label }}">Ficam de fora sozinhos, sem você pedir</h3>
    {{-- O começo de cada item em negrito branco, como no canvas: dá para ler a lista só pelos começos. --}}
    <ul class="list-disc space-y-1.5 pl-5 text-sm text-ink-200">
        <li>
            <b class="font-semibold text-white">Subdomínio que aponta para serviço de outra empresa</b> (CNAME para fora do seu domínio, como loja, e-mail marketing ou central de ajuda
            hospedados por terceiros). O TXT prova o seu DNS, não o servidor deles. Só olhamos o DNS, para avisar se ele foi abandonado; nunca visitamos o serviço.
        </li>
        <li>
            <b class="font-semibold text-white">Portas de CDN, de balanceador de nuvem e de hospedagem compartilhada.</b> Ali testamos só o site pelo nome (HTTP e HTTPS).
            Nunca procuramos o servidor de origem escondido atrás da CDN.
        </li>
        <li>
            <b class="font-semibold text-white">Nomes que não conseguimos ligar a você:</b> sem certificado válido para o nome, só com endereço IPv6 (o Security Monitor ainda não testa por IPv6)
            ou com servidor de nomes próprio sem o TXT.
        </li>
        <li><b class="font-semibold text-white">Os IPs do próprio Security Monitor</b> e endereços internos.</li>
    </ul>
</section>

<div class="grid gap-4 md:grid-cols-3">
    <section class="{{ $box }}">
        <h3 class="text-sm font-semibold text-white">Portas além de 80 e 443</h3>
        <p class="text-sm text-ink-200">
            Só em servidor dedicado ao seu domínio: o IP responde com certificado válido para um nome do escopo, não é CDN, nuvem gerenciada nem
            hospedagem compartilhada, e dá mais um sinal (o certificado padrão do IP ou o DNS reverso apontando para o seu domínio).
        </p>
    </section>
    <section class="{{ $box }}">
        <h3 class="text-sm font-semibold text-white">Quando a lista fecha</h3>
        <p class="text-sm text-ink-200">
            A lista de alvos nasce no começo do teste, depois de ler o DNS e os certificados públicos, e fica fechada: guardamos uma impressão digital dela.
            Só esses alvos recebem requisições. Ampliar a regra pede um aceite novo.
        </p>
    </section>
    <section class="{{ $box }}">
        <h3 class="text-sm font-semibold text-white">Precisa de TXT próprio</h3>
        <p class="text-sm text-ink-200">Subdomínio com servidor de nomes próprio (NS) só entra com o TXT no nome dele, do mesmo jeito do passo 2.</p>
    </section>
</div>

{{-- data-interview liga o JS dos nomes em chips: sem ele, o Enter envia o formulário e pula para o passo 4. --}}
<form id="step-form" method="POST" action="{{ route('alerts.tests.authorize.update', ['passo' => 3, 'dominio' => $domain->host]) }}" data-interview
      class="{{ $box }}">
    @csrf
    @method('PUT')
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <label for="exclusoes" class="text-[15px] font-semibold text-white">Nunca testar estes nomes</label>
        <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] tracking-wider text-ink-400 uppercase">opcional</span>
    </div>
    {{-- O campo dos nomes tira o contorno próprio: o contêiner ganha o anel de foco (o contorno global, de 2 px). --}}
    <div data-tags data-name="exclusoes[]" class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-850 p-2 focus-within:border-accent focus-within:ring-2 focus-within:ring-accent">
        @foreach ((array) old('exclusoes', $exclusions) as $item)
            @continue(trim((string) $item) === '')
            <span data-tag class="inline-flex max-w-full items-center gap-1 rounded-md bg-ink-800 py-0.5 pr-1 pl-2.5 font-mono text-sm text-white">
                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $item }}</span>
                <input type="hidden" name="exclusoes[]" value="{{ $item }}">
                <button type="button" data-tag-remove aria-label="Remover {{ $item }}" class="relative grid size-7 place-items-center rounded text-ink-400 before:absolute before:-inset-2 before:content-[''] hover:text-white">&times;</button>
            </span>
        @endforeach
        <input id="exclusoes" data-tags-input name="exclusoes[]" type="text" autocomplete="off" spellcheck="false" maxlength="253" placeholder="Digite e tecle Enter"
               class="min-h-8 min-w-40 flex-1 border-0 bg-transparent px-1 font-mono text-sm text-white placeholder:text-ink-400 focus:outline-none">
    </div>
    <p class="text-xs text-ink-400">Tudo o que você listar aqui fica fora, mesmo sendo seu. Tecle Enter ou vírgula depois de cada nome.</p>
    @error('exclusoes')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
    @error('exclusoes.*')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
</form>
