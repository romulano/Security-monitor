{{--
    Os subdomínios que o Radar de empresa vigia (E4a): o DNS de cada um, relido em toda varredura completa, e o estado.
    Só aparece com a chave ligada e no Radar de empresa ($subdomains não nulo). Recebe $subdomains e $domain.
--}}
@php
    $date = fn ($value) => $value?->timezone('America/Sao_Paulo')->format('d/m/Y');
    $mono = 'font-mono [overflow-wrap:anywhere]';
@endphp
<section class="rounded-xl border border-ink-800 bg-ink-900 px-5 py-4" data-radar-subdomains>
    <h2 class="font-semibold text-white">Subdomínios vigiados</h2>
    <p class="mt-1 text-sm text-ink-400">
        Em cada varredura, o Radar relê o DNS dos subdomínios ativos de <span class="{{ $mono }}">{{ $domain->domain }}</span> e avisa
        quando um deles passa a apontar para fora da empresa ou para um destino que não existe (quem criar o destino passa a
        responder pelo endereço). Só DNS: nada é aberto nos servidores da empresa.
    </p>
    @if ($subdomains->isEmpty())
        <p class="mt-3 text-sm text-ink-400">
            Nenhum subdomínio ativo em <span class="{{ $mono }}">{{ $domain->domain }}</span>.
            @if (Route::has('company.domains'))
                Quem configura o domínio cadastra em <a href="{{ route('company.domains') }}" class="text-accent hover:underline">Domínios da empresa</a>.
            @endif
        </p>
    @else
        <ul class="mt-3 divide-y divide-ink-800 rounded-lg border border-ink-800">
            @foreach ($subdomains as $sub)
                @php
                    $takeover = \App\Services\Lookalike\RadarSubdomains::takeover($sub->facts ?? []);
                    [$label, $tone] = match (true) {
                        $takeover => ['destino não existe: risco de tomada', 'border-red-900 text-red-300'],
                        $sub->isOutside() => ['aponta para fora', 'border-amber-800 text-amber-200'],
                        ! empty($sub->facts['ips'] ?? []) => ['na empresa', 'border-emerald-800 text-emerald-300'],
                        default => ['não resolve', 'border-ink-700 text-ink-400'],
                    };
                @endphp
                <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" data-radar-subdomain="{{ $sub->id }}">
                    <div class="min-w-0">
                        <p class="text-sm text-ink-200 {{ $mono }}">{{ $sub->name }}</p>
                        @if (($takeover || $sub->isOutside()) && $sub->reasonLabel())
                            <p class="text-xs text-ink-400">{{ ucfirst($sub->reasonLabel()) }}.</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full border px-2 py-0.5 {{ $tone }}">{{ $label }}</span>
                        @if ($sub->last_seen_at)
                            <span class="text-ink-400">respondeu em {{ $date($sub->last_seen_at) }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
