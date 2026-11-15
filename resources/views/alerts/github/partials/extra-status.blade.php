{{-- O que a IA fez com o texto "Adicional". Espera $monitor. --}}
@php
    $labels = ['key_names' => 'Nomes de chaves', 'token_prefixes' => 'Prefixos de token', 'repositories' => 'Repositórios privados', 'hostnames' => 'Endereços internos', 'terms' => 'Outros termos'];
@endphp

@switch($monitor->extra_status)
    @case('pending')
        <div class="rounded-xl border border-accent/40 bg-accent/5 px-5 py-4 text-sm text-ink-200">
            <p class="font-semibold text-white">A IA está lendo o seu texto.</p>
            <p class="mt-1 text-ink-400">{{ $monitor->extra_error ?: 'Leva alguns segundos. Recarregue a página para ver o que ela entendeu; a varredura também lê antes de começar.' }}</p>
        </div>
        @break

    @case('done')
        <div class="rounded-xl border border-emerald-900/70 bg-emerald-950/20 px-5 py-4 text-sm">
            <p class="font-semibold text-white">O que a IA entendeu do seu texto</p>
            @if ($monitor->extraContext())
                <p class="mt-1 text-ink-200">“{{ $monitor->extraContext() }}”</p>
            @endif
            @php $any = false; @endphp
            <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                @foreach ($labels as $field => $label)
                    @if ($values = $monitor->extra($field))
                        @php $any = true; @endphp
                        <div>
                            <dt class="text-[11px] tracking-widest text-ink-400 uppercase">{{ $label }}</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                @foreach ($values as $value)
                                    <span class="rounded bg-ink-800 px-2 py-0.5 font-mono text-xs text-white">{{ $value }}</span>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                @endforeach
            </dl>
            @unless ($any)
                <p class="mt-2 text-ink-400">Nenhum termo novo para buscar: o texto vale como contexto para a IA julgar os achados.</p>
            @endunless
            <p class="mt-3 text-xs text-ink-400">A IA só aproveita o que está escrito no seu texto, e isso só acrescenta buscas: não muda nível, corte nem para onde vão os avisos.</p>
        </div>
        @break

    @case('flagged')
        <div class="rounded-xl border border-red-900 bg-red-950/30 px-5 py-4 text-sm text-red-200">
            <p class="font-semibold text-white">Este texto não foi para a IA.</p>
            <p class="mt-1">Ele parece conter instruções para a IA (como “ignore as regras” ou “dê 100%”). Ficou guardado, mas não é usado. Escreva só o que preocupa vocês no GitHub.</p>
        </div>
        @break

    @case('failed')
        <div class="rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4 text-sm text-amber-100">
            <p class="font-semibold text-white">A IA não conseguiu ler o texto agora.</p>
            <p class="mt-1">Ele ficou salvo; a próxima varredura tenta de novo antes de começar.</p>
        </div>
        @break

    @case('off')
        <div class="rounded-xl border border-ink-800 bg-ink-900 px-5 py-4 text-sm text-ink-400">
            Texto guardado. A leitura pela IA está desligada no momento; ele passa a valer quando ela voltar.
        </div>
        @break
@endswitch
