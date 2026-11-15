{{-- Passo 2 (Autorizar-2): a posse pelo registro TXT, a mesma prova da conta (no próprio nome), e a última reconferência. --}}
@php
    $copy = $domain->domain;
    $date = fn ($value) => $value?->timezone('America/Sao_Paulo')->format('d/m');
    $time = fn ($value) => $value?->timezone('America/Sao_Paulo')->format('H:i');
    $check = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold';
    // O domínio inteiro numa linha: "labs-" e "empresa.com.br" em linhas diferentes liam como dois nomes.
    $name = 'inline-block max-w-full font-mono [overflow-wrap:anywhere]';
@endphp

<div>
    <h2 class="text-lg font-semibold text-white">Comprove que o domínio é seu</h2>
    <p class="text-sm text-ink-400">
        A mesma prova dos outros serviços do Security Monitor: um registro TXT no DNS de <span class="{{ $name }}">{{ $domain->host }}</span>. Assim ninguém usa o Security Monitor para testar o site dos outros.
    </p>
</div>

@if ($ownership === 'verified')
    <section class="space-y-3 rounded-xl border border-emerald-900 bg-emerald-950/30 px-5 py-4">
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-800 bg-emerald-950/60 px-2.5 py-0.5 text-xs text-emerald-300">
                <span class="size-1.5 rounded-full bg-emerald-400"></span>posse comprovada
            </span>
            @if ($domain->ownership_checked_at)
                <span class="text-xs text-emerald-200">Conferido em {{ $date($domain->ownership_checked_at) }} às {{ $time($domain->ownership_checked_at) }}</span>
            @endif
        </div>
        <p class="text-sm text-emerald-100">
            Encontramos o TXT em <span class="font-mono">{{ $domain->host }}</span> em {{ $date($copy->verified_at) }}{{ $proofOrigin ? ', '.$proofOrigin : '' }}.
            Mantenha o registro no ar: durante o teste conferimos de novo no início, a cada fase e a cada 15 minutos. Se ele sumir, o teste para.
        </p>
        <form method="POST" action="{{ route('alerts.tests.domains.verify', ['dominio' => $domain->host]) }}">
            @csrf
            <button class="{{ $check }} border border-emerald-800 text-emerald-200 hover:border-emerald-400">Conferir de novo</button>
        </form>
    </section>
@elseif ($ownership === 'unknown')
    <section class="space-y-3 rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4">
        <p class="text-sm text-amber-100">
            O DNS de <span class="{{ $name }}">{{ $domain->host }}</span> não respondeu na última conferência. Isso não apaga a prova que você já tem.
            Tente de novo em alguns minutos; durante um teste, a mesma situação pausa a execução e tentamos de novo por até 20 minutos.
        </p>
        <form method="POST" action="{{ route('alerts.tests.domains.verify', ['dominio' => $domain->host]) }}">
            @csrf
            <button class="{{ $check }} bg-amber-300 text-amber-950 hover:brightness-110">Verificar agora</button>
        </form>
    </section>
@else
    <section class="space-y-3 rounded-xl border {{ $ownership === 'missing' ? 'border-red-900 bg-red-950/30' : 'border-amber-800 bg-amber-950/30' }} px-5 py-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="font-semibold text-white">{{ $ownership === 'missing' ? 'Publique de novo este registro TXT no DNS de' : 'Crie este registro TXT no DNS de' }} <span class="{{ $name }}">{{ $domain->host }}</span></p>
            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $ownership === 'missing' ? 'border-red-900 text-red-300' : 'border-amber-800 text-amber-200' }}">
                <span class="size-1.5 rounded-full {{ $ownership === 'missing' ? 'bg-red-400' : 'bg-amber-300' }}"></span>{{ $ownership === 'missing' ? 'TXT sumiu' : 'aguardando o TXT' }}
            </span>
        </div>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm sm:grid-cols-[90px_90px_minmax(0,1fr)]">
            <dt class="text-ink-400 sm:order-1">Tipo</dt><dd class="font-mono text-white sm:order-4">TXT</dd>
            <dt class="text-ink-400 sm:order-2">Nome</dt><dd class="font-mono text-white sm:order-5">@</dd>
            <dt class="text-ink-400 sm:order-3">Valor</dt>
            <dd class="flex flex-wrap gap-2 sm:order-6">
                <input id="txt-valor" readonly value="{{ $copy->txtValue() }}" aria-label="Valor do registro TXT"
                       class="min-h-11 min-w-0 flex-[1_1_15rem] rounded-md border border-ink-800 bg-ink-950 px-2.5 font-mono text-[13px] text-white">
                <button type="button" data-copy="#txt-valor" class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-3 text-sm text-ink-200 hover:border-ink-400">Copiar</button>
            </dd>
        </dl>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="min-w-0 flex-[1_1_320px] text-sm {{ $ownership === 'missing' ? 'text-red-200' : 'text-amber-200' }}">
                @if ($ownership === 'missing')
                    O registro sumiu do DNS na última conferência{{ $domain->ownership_checked_at ? ', em '.$date($domain->ownership_checked_at).' às '.$time($domain->ownership_checked_at) : '' }}.
                    Publique de novo: sem ele, não dá para autorizar nem testar.
                @elseif ($accountProof)
                    Este domínio já está comprovado {{ $accountProof }}: clique em “Verificar agora” para usar a mesma prova. Conferimos o TXT no DNS na hora.
                @else
                    Conferimos sozinhos a cada 10 minutos, por 72 horas. Registros novos levam alguns minutos para propagar.
                    Pode seguir para o próximo passo enquanto isso; só não dá para autorizar sem ele.
                @endif
            </p>
            <form method="POST" action="{{ route('alerts.tests.domains.verify', ['dominio' => $domain->host]) }}">
                @csrf
                <button class="{{ $check }} bg-amber-300 text-amber-950 hover:brightness-110">Verificar agora</button>
            </form>
        </div>
    </section>
@endif

<ul class="space-y-1 text-xs text-ink-400">
    <li>O TXT fica no domínio principal (@). Subdomínio com servidor de nomes próprio (NS) precisa do TXT no próprio nome: o passo 3 mostra quais.</li>
    <li>Por que conferimos de novo: um domínio vendido, vencido ou sem o registro não pode continuar autorizado.</li>
</ul>

<form id="step-form" method="POST" action="{{ route('alerts.tests.authorize.update', ['passo' => 2, 'dominio' => $domain->host]) }}">
    @csrf
    @method('PUT')
</form>
