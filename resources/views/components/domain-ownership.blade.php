{{--
    O quadro da posse de domínio, o mesmo em Notícias, no Radar de domínios, no monitor de sites e no
    webhook: o registro TXT a criar e o "Verificar agora", ou a posse já comprovada. A posse é da conta
    (DomainOwnership): comprovada num serviço, nenhum outro pede de novo.

    - state: o que DomainOwnership::screen() devolve;
    - action: a rota do "Verificar agora" (nula: sem botão);
    - waiting: o agendador procura o TXT sozinho por 72 h (a frase aparece);
    - effect: o que para no serviço se a posse cair ("a descoberta para", "o Radar pausa");
    - button: o nome do botão (no monitor, "Conferir agora": o "Verificar agora" da página é a varredura);
    - o slot: a frase do serviço, ao lado do botão ("O Radar começa sozinho assim que o registro aparecer.").
--}}
@props(['state', 'action' => null, 'waiting' => false, 'effect' => null, 'field' => 'txt-posse', 'button' => 'Verificar agora'])

@php
    $date = fn ($value) => $value?->timezone('America/Sao_Paulo')->format('d/m/Y');
    // O domínio inteiro numa linha: "labs-" e "empresa.com.br" em linhas diferentes liam como dois nomes.
    $mono = 'inline-block max-w-full font-mono [overflow-wrap:anywhere]';
    $subdomain = $state['name'] !== $state['zone'];
    $fading = $state['state'] === 'unseen' && ! $state['legacy'];
@endphp

@if ($state['state'] === 'pending' || $fading)
    <section {{ $attributes->class(['space-y-3 rounded-xl border px-5 py-4', $fading ? 'border-red-900 bg-red-950/30' : 'border-amber-800 bg-amber-950/30']) }}>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="font-semibold text-white">
                {{ $fading ? 'Publique de novo este registro TXT no DNS de' : 'Crie este registro TXT no DNS de' }}
                <span class="{{ $mono }}">{{ $fading ? $state['proofName'] : $state['zone'] }}</span>
            </p>
            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $fading ? 'border-red-900 text-red-300' : 'border-amber-800 text-amber-200' }}">
                <span class="size-1.5 rounded-full {{ $fading ? 'bg-red-400' : 'bg-amber-300' }}"></span>{{ $fading ? 'TXT sumiu' : 'aguardando o TXT' }}
            </span>
        </div>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm sm:grid-cols-[90px_90px_minmax(0,1fr)]">
            <dt class="text-ink-400 sm:order-1">Tipo</dt><dd class="font-mono text-white sm:order-4">TXT</dd>
            <dt class="text-ink-400 sm:order-2">Nome</dt><dd class="font-mono text-white sm:order-5">@</dd>
            <dt class="text-ink-400 sm:order-3">Valor</dt>
            <dd class="flex flex-wrap gap-2 sm:order-6">
                {{-- O valor quebra a linha a 390 px e se lê inteiro (Designer, rodada 12); o Copiar lê o campo escondido. --}}
                <input type="hidden" id="{{ $field }}" value="{{ $state['txt'] }}">
                <code class="flex min-h-11 min-w-0 flex-[1_1_15rem] items-center rounded-md border border-ink-800 bg-ink-950 px-2.5 py-2 font-mono text-[13px] break-all text-white select-all">{{ $state['txt'] }}</code>
                <button type="button" data-copy="#{{ $field }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-3 text-sm text-ink-200 hover:border-ink-400">Copiar</button>
            </dd>
        </dl>
        @php
            $where = $fading ? $state['proofName'] : $state['zone'];
            $steps = [
                'Entre no painel onde o domínio é gerenciado (Registro.br, Cloudflare, GoDaddy, Hostinger, Locaweb ou o provedor do seu site) e abra a parte de DNS (às vezes "Zona DNS" ou "Editar zona").',
                'Adicione um registro novo: tipo TXT; nome @ (se o painel não aceitar @, deixe em branco ou use o próprio domínio); valor: o texto acima, inteiro.',
                'Salve. Não mexa nos outros registros.',
                'Volte aqui e clique em '.$button.'. Costuma levar alguns minutos; em alguns provedores, até algumas horas.',
            ];
            $instructions = "Crie este registro no DNS de {$where}:\nTipo: TXT\nNome: @ (se o painel não aceitar @, deixe em branco ou use o próprio domínio)\nValor: {$state['txt']}\n\n"
                .collect(array_slice($steps, 0, 3))->map(fn ($step, $i) => ($i + 1).'. '.str_replace('o texto acima, inteiro', 'o texto da linha Valor, inteiro', $step))->implode("\n")
                ."\n4. Avise quando estiver salvo: a conferência costuma levar alguns minutos; em alguns provedores, até algumas horas.";
        @endphp
        {{-- O passo a passo para quem não é técnico, e as instruções para mandar a quem cuida do DNS (Designer, rodada 12). --}}
        <details class="text-sm">
            <summary class="cursor-pointer {{ $fading ? 'text-red-200' : 'text-amber-200' }} hover:underline">Como criar o registro</summary>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-ink-200">
                @foreach ($steps as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <p class="min-w-0 flex-[1_1_15rem] text-ink-400">Não cuida do DNS? Copie estas instruções e mande para quem cuida.</p>
                <textarea id="{{ $field }}-como" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $instructions }}</textarea>
                <button type="button" data-copy="#{{ $field }}-como" class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-3 text-sm text-ink-200 hover:border-ink-400">Copiar as instruções</button>
            </div>
        </details>
        @if ($fading)
            <p class="text-sm text-red-200">
                O registro não está mais no DNS desde {{ $date($state['unseenSince']) }}. Publique de novo o mesmo valor até {{ $date($state['fallsAt']) }}:
                sem ele, a posse cai{{ $effect ? ' e '.$effect : '' }}.
            </p>
        @elseif ($subdomain)
            <p class="text-sm text-amber-100">
                No domínio principal, o registro vale para <span class="{{ $mono }}">{{ $state['name'] }}</span> e para todos os outros subdomínios.
                Para comprovar só este nome, crie o registro com o nome <span class="font-mono">{{ $state['label'] }}</span>.
            </p>
        @endif
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="min-w-0 flex-[1_1_320px] text-sm {{ $fading ? 'text-red-200' : 'text-amber-200' }}">
                {{ $slot }}
                @if ($waiting && ! $fading)
                    Conferimos sozinhos a cada 10 minutos, por 72 horas{{ $state['checkedAt'] ? ' (a última, às '.$state['checkedAt']->timezone('America/Sao_Paulo')->format('H:i').')' : '' }}.
                    Registros novos levam alguns minutos para propagar.
                @endif
            </p>
            @if ($action)
                <form method="POST" action="{{ $action }}">
                    @csrf
                    <button class="inline-flex min-h-11 items-center rounded-lg bg-amber-300 px-4 text-sm font-semibold text-amber-950 hover:brightness-110">{{ $button }}</button>
                </form>
            @endif
        </div>
        @unless ($fading)
            <p class="text-xs text-ink-400">É o mesmo registro para todos os serviços do Security Monitor: com ele no DNS, nenhum outro pede de novo.</p>
        @endunless
    </section>
@else
    <section {{ $attributes->class(['flex flex-wrap items-center gap-3 rounded-xl border border-emerald-900 bg-emerald-950/30 px-5 py-4']) }}>
        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-800 bg-emerald-950/60 px-2.5 py-0.5 text-xs text-emerald-300">
            <span class="size-1.5 rounded-full bg-emerald-400"></span>posse comprovada
        </span>
        <p class="min-w-0 flex-[1_1_320px] text-sm text-emerald-100">
            Encontramos o TXT em <span class="{{ $mono }}">{{ $state['proofName'] }}</span> em {{ $date($state['verifiedAt']) }}{{ $state['origin'] ? ', '.$state['origin'] : '' }}.
            @if ($state['proofName'] !== $state['name'])
                Ele vale para <span class="{{ $mono }}">{{ $state['name'] }}</span> e para os outros subdomínios.
            @endif
            @if ($state['state'] === 'unseen')
                O registro não está mais no DNS, mas a comprovação de antes continua valendo; publicar de novo o mesmo valor deixa ela em dia.
            @else
                Mantenha o registro no ar: conferimos uma vez por dia, e sem ele por {{ config('secmon.ownership.grace_days') }} dias a posse cai.
            @endif
            {{ $slot }}
        </p>
    </section>
@endif
