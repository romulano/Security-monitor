{{-- Passo 5 (Autorizar-5): o aviso ao time (SOC, firewall, monitoração) com os números da regra, e a confirmação de que avisou. --}}
@php
    use App\Services\Pentest\PentestRule;

    $box = 'space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5';
    $copy = 'inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-3 text-sm text-ink-200 hover:border-ink-400';
@endphp

<div>
    <h2 class="text-lg font-semibold text-white">Avise quem cuida do site e do firewall</h2>
    <p class="text-sm text-ink-400">
        Mande este texto ao time interno, ao SOC ou ao MSSP antes do teste. Assim ninguém confunde o teste com um ataque, e todo mundo sabe como pará-lo.
    </p>
</div>

@if (! $rule)
    <section class="space-y-1 rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4 text-sm text-amber-100">
        <p>{{ $ruleError }}</p>
        <p>
            O aviso sai com os números do passo 4.
            <a href="{{ route('alerts.tests.authorize', ['passo' => 4, 'dominio' => $domain->host]) }}" class="font-semibold underline">Volte ao passo 4</a> e grave a janela de novo.
        </p>
    </section>
@else
    @php
        $ips = $rule['egress']['ips'];
        $expression = '(ip.src in {'.($ips ? implode(' ', $ips) : '[IP de saída]').'} and any(http.request.headers["'.strtolower(PentestRule::RUN_HEADER).'"][*] eq "<número completo da execução>"))';
        $until = \Illuminate\Support\Carbon::parse($rule['window']['valid_until'])->format('d/m');
    @endphp

    <section class="{{ $box }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-[15px] font-semibold text-white">Texto pronto, em português</h3>
                @if ($legalPending)
                    <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] tracking-wider text-ink-400 uppercase">Texto final · aguarda parecer jurídico</span>
                @endif
            </div>
            <button type="button" data-copy="#aviso" class="{{ $copy }}">Copiar</button>
        </div>
        <textarea id="aviso" readonly rows="18" aria-label="Aviso ao time"
                  class="block w-full rounded-lg border border-ink-800 bg-ink-950 p-3 font-mono text-[13px] leading-5 text-ink-200">{{ $notice }}</textarea>
        <p class="text-xs text-ink-400">
            Cada execução tem um número. A página do domínio traz este mesmo texto com o número da próxima execução no assunto, no User-Agent,
            no cabeçalho e na regra, para copiar de novo. O User-Agent mostrado aqui vem da mesma configuração que monta as requisições.
        </p>
    </section>

    <section class="{{ $box }}">
        <h3 class="text-[15px] font-semibold text-white">Como reconhecer o Security Monitor</h3>
        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[140px_minmax(0,1fr)]">
            <dt class="text-ink-400">IP de saída</dt><dd class="font-mono text-white">{{ $values['ip_saida'] }}</dd>
            <dt class="text-ink-400">DNS reverso</dt><dd class="font-mono text-white">{{ $values['dns_reverso'] }}</dd>
            <dt class="text-ink-400">User-Agent</dt><dd class="font-mono break-all text-white">{{ sprintf(PentestRule::USER_AGENT, '<8 caracteres>') }}</dd>
            <dt class="text-ink-400">Cabeçalho</dt><dd class="font-mono break-all text-white">{{ PentestRule::RUN_HEADER }}: &lt;número completo da execução&gt;</dd>
            <dt class="text-ink-400">Comparação</dt><dd class="text-white">pedidos a <span class="font-mono">/secmon-ativo-&lt;16 caracteres&gt;</span>, endereços que não existem</dd>
            <dt class="text-ink-400">Janela</dt><dd class="text-white">{{ $values['janela_curta'] }}</dd>
            <dt class="text-ink-400">Volume</dt><dd class="text-white">até {{ $values['rps'] }} req/s por site · até {{ $values['max_requisicoes'] }} no total</dd>
        </dl>
        <p class="text-xs text-ink-400">
            Confie no IP: qualquer um pode copiar o User-Agent. Portas e certificados só se identificam pelo IP. As consultas de DNS e de certificados
            públicos saem pelo DNS do servidor do Security Monitor, não pelo IP acima. Tudo isso fica em example.com/scanner.
        </p>
    </section>

    <details class="group rounded-xl border border-ink-800 bg-ink-900" @if ($rule['waf'] === 'release') open @endif>
        <summary class="flex min-h-11 cursor-pointer flex-wrap items-center gap-2 px-5 py-3 text-[15px] font-semibold text-white">
            Se o seu time quiser liberar o Security Monitor só durante o teste
            <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] font-normal tracking-wider text-ink-400 uppercase">opcional</span>
        </summary>
        <div class="space-y-3 border-t border-ink-800 px-5 py-4 text-sm text-ink-200">
            <p>
                O padrão é testar com o firewall ligado, como o mundo vê o site. Se o time quiser ver o que há atrás dele, use uma regra com três condições,
                todas obrigatórias: o IP de saída do Security Monitor, <b class="text-white">e</b> o cabeçalho com o número desta execução, <b class="text-white">e</b> só durante a janela.
                Nunca uma liberação pelo User-Agent nem pelo nome “SecurityMonitorBot”: qualquer um copia, e ela abriria também a leitura passiva.
            </p>
            <p>
                Na Cloudflare: Security → WAF → Custom rules → Create rule, com o nome <span class="font-mono text-white">Liberar teste do Security Monitor #&lt;8 caracteres&gt;</span>,
                ação Skip (nos componentes que o time escolher) e posição First.
            </p>
            <div class="space-y-1.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label for="regra-waf" class="text-xs text-ink-400">Expressão</label>
                    <button type="button" data-copy="#regra-waf" class="{{ $copy }}">Copiar</button>
                </div>
                <textarea id="regra-waf" readonly rows="3" class="block w-full rounded-lg border border-ink-800 bg-ink-950 p-3 font-mono text-[13px] text-ink-200">{{ $expression }}</textarea>
            </div>
            <p>
                Em outro firewall, a mesma regra em palavras: liberar só o IP {{ $values['ip_saida'] }}, só quando o cabeçalho {{ PentestRule::RUN_HEADER }} trouxer
                o número desta execução, só na janela.
            </p>
            <p>Remova a regra no fim da janela, até {{ $until }}. O relatório diz qual modo foi usado: com o firewall ligado ou com a liberação restrita.</p>
        </div>
    </details>

    <form id="step-form" method="POST" action="{{ route('alerts.tests.authorize.update', ['passo' => 5, 'dominio' => $domain->host]) }}" class="space-y-2">
        @csrf
        @method('PUT')
        <input type="hidden" name="regra" value="{{ PentestRule::hash($rule) }}">
        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5">
            <input type="checkbox" name="avisei" value="1" required @checked(old('avisei', $acked)) class="mt-0.5 size-5 shrink-0 accent-accent">
            <span class="text-sm text-white">Avisei o time responsável pelo site, pelo firewall e pela monitoração (ou sou eu quem cuida deles).</span>
        </label>
        @if (! $acked && ($domain->draft['notice'] ?? null))
            <p class="text-sm text-amber-200">A janela ou o ritmo mudou depois que você confirmou: mande o texto novo ao time e confirme de novo.</p>
        @endif
        @foreach ($errors->all() as $message)
            <p class="text-sm text-red-300">{{ $message }}</p>
        @endforeach
    </form>
@endif
