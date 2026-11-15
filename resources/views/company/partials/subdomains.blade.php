{{--
    Os subdomínios de um domínio da empresa (E3b, R6), dentro do cartão do domínio na tela de domínios. Recebe
    $domain (OwnedDomain) e $subs = CompanySubdomains::screen($user, $domain). Quem só vê o domínio vê a lista sem botões.
--}}
@php
    $input = 'min-h-11 w-full min-w-0 rounded-lg border border-ink-800 bg-ink-900 px-3 text-sm focus:border-accent';
    $button = 'min-h-11 shrink-0 rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-accent';
@endphp
<div class="mt-4 border-t border-ink-800 pt-3" data-subdomains="{{ $domain->id }}">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="text-sm font-semibold text-white">Subdomínios</h3>
        <span class="text-xs text-ink-400">{{ $subs['used'] }} de {{ $subs['limit'] }} · não contam no limite de domínios</span>
    </div>
    <p class="mt-1 text-xs text-ink-400">Subdomínios são endereços que terminam em {{ $domain->domain }}, como login.{{ $domain->domain }} ou loja.{{ $domain->domain }}. A posse de {{ $domain->domain }} já vale para eles: não precisa de outro registro TXT.@if (config('secmon.lookalikes.subdomains_enabled')) Os cadastrados aqui entram no Radar.@endif</p>

    @if ($subs['active']->isNotEmpty())
        <ul class="mt-3 divide-y divide-ink-800 rounded-md border border-ink-800">
            @foreach ($subs['active'] as $sub)
                <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                    <div class="min-w-0">
                        <p class="break-all text-ink-200">{{ $sub->name }}</p>
                        @if ($sub->isOutside())
                            <p class="text-xs text-amber-300">Hospedado fora: {{ $sub->reasonLabel() }}.@if (config('secmon.lookalikes.subdomains_enabled')) O Radar vigia imitações dele, mas não o trata como servidor da empresa.@endif</p>
                        @endif
                    </div>
                    @if ($subs['manages'])
                        <form method="POST" action="{{ route('company.subdomains.remove', $sub) }}"
                              data-confirm="Tirar {{ $sub->name }} da lista de subdomínios? A descoberta automática não o traz de volta; dá para cadastrar de novo."
                              data-confirm-ok="Tirar" data-confirm-tone="primary">
                            @csrf
                            @method('DELETE')
                            <button class="min-h-11 rounded-md border border-ink-800 px-3 text-ink-200 hover:border-red-500">Tirar</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-3 text-sm text-ink-400">Nenhum subdomínio ainda.</p>
    @endif

    @if ($subs['manages'])
        @if ($subs['used'] < $subs['limit'])
            <form method="POST" action="{{ route('company.subdomains.store', $domain) }}" class="mt-3 flex gap-2">
                @csrf
                <label class="sr-only" for="sub-{{ $domain->id }}">Subdomínio de {{ $domain->domain }}</label>
                <input id="sub-{{ $domain->id }}" name="nome" required maxlength="300" autocomplete="off" spellcheck="false"
                       placeholder="exemplo: app.{{ $domain->domain }}" class="{{ $input }}">
                <button class="{{ $button }}">Cadastrar</button>
            </form>
            <p class="mt-1 text-xs text-ink-400">Ele precisa estar no ar (resolver no DNS).</p>
        @else
            <p class="mt-3 text-xs text-ink-400">{{ $domain->domain }} chegou ao teto de {{ $subs['limit'] }} subdomínios. Tire um para cadastrar outro.</p>
        @endif
    @endif

    @if ($subs['suggested']->isNotEmpty() || $subs['ignored']->isNotEmpty())
        <details class="mt-3">
            <summary class="cursor-pointer text-xs text-ink-400 hover:underline">
                Sugestões ({{ $subs['suggested']->count() }}) · Tirados ou fora do ar ({{ $subs['ignored']->count() }})
            </summary>
            <p class="mt-2 text-xs text-ink-400">Sugestões: achados em certificados públicos e no DNS que não entraram sozinhos (o motivo está em cada um).</p>
            <ul class="mt-2 space-y-2 text-sm">
                @foreach ($subs['suggested'] as $sub)
                    <li class="flex flex-wrap items-center justify-between gap-2">
                        <span class="min-w-0 break-all text-ink-200">{{ $sub->name }}@if ($sub->reasonLabel())<span class="block text-xs text-ink-400">{{ ucfirst($sub->reasonLabel()) }}</span>@endif</span>
                        @if ($subs['manages'] && $subs['used'] < $subs['limit'])
                            <form method="POST" action="{{ route('company.subdomains.store', $domain) }}">
                                @csrf
                                <input type="hidden" name="nome" value="{{ $sub->name }}">
                                <button class="min-h-11 rounded-md border border-ink-800 px-3 text-ink-200 hover:border-accent">Cadastrar</button>
                            </form>
                        @endif
                    </li>
                @endforeach
                @foreach ($subs['ignored'] as $sub)
                    <li class="flex flex-wrap items-center justify-between gap-2">
                        <span class="min-w-0 break-all text-ink-400">{{ $sub->name }} · {{ $sub->status === 'gone' ? 'deixou de resolver' : 'tirado' }}</span>
                        @if ($subs['manages'] && $subs['used'] < $subs['limit'])
                            <form method="POST" action="{{ route('company.subdomains.store', $domain) }}">
                                @csrf
                                <input type="hidden" name="nome" value="{{ $sub->name }}">
                                <button class="min-h-11 rounded-md border border-ink-800 px-3 text-ink-200 hover:border-accent">Cadastrar de novo</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
