{{--
    A liberação da conta pelo admin (PentestAccess): sem ela, a área mostra só como pedir. Espera $access
    (none, requested ou revoked), $requestedAt, $revokedAt e $domains. Com a liberação retirada, o histórico
    continua abaixo (aí o título da página é o da lista), e revogar e excluir continuam valendo.
--}}
@php
    $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m \à\s H:i');
    $alone = $domains->isEmpty();
    $title = $access === 'revoked'
        ? 'A liberação dos testes de segurança foi retirada da sua conta.'
        : 'Os testes de segurança ainda não foram liberados para a sua conta.';
@endphp

<section aria-label="Liberação dos testes de segurança" role="status" class="max-w-2xl space-y-3 rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4">
    @if ($alone)
        @include('alerts.partials.crumbs', ['here' => 'Testes de segurança'])
        <h1 class="text-lg font-semibold text-white">{{ $title }}</h1>
    @else
        <p class="text-[15px] font-semibold text-white">{{ $title }}</p>
    @endif

    @if ($access === 'requested')
        <p class="text-sm text-amber-100">Pedido enviado em {{ $when($requestedAt) }}. O administrador vai analisar.</p>
    @else
        <p class="text-sm text-amber-100">
            @if ($access === 'revoked')
                Retirada em {{ $when($revokedAt) }}. Para usar de novo, peça a liberação ao administrador do Security Monitor.
                O que já foi feito continua aqui: dá para ver, revogar e excluir.
            @else
                Peça a liberação ao administrador do Security Monitor.
            @endif
        </p>
        <form method="POST" action="{{ route('alerts.tests.access.request') }}">
            @csrf
            <button class="inline-flex min-h-11 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Pedir liberação</button>
        </form>
    @endif
</section>
