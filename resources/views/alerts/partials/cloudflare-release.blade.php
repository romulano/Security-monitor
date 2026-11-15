@php
    // Aberto e "necessário" quando algo já foi barrado; senão, fica como recomendação.
    $blocked = $domain->walls() !== [];
    $ips = config('secmon.alerts.discovery.egress_ips', []);
    $tag = 'rounded-full border px-2 py-0.5 text-[11px] tracking-wider uppercase';
@endphp

<details id="liberar-cloudflare" @if ($blocked) open @endif
         class="group rounded-lg border {{ $blocked ? 'border-amber-800 bg-amber-950/20' : 'border-ink-800 bg-ink-850' }}">
    <summary class="flex min-h-11 cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-4 py-3">
        <span class="flex items-center gap-2 font-semibold text-white">
            <svg class="size-4 shrink-0 text-ink-400 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            Liberar o Security Monitor na Cloudflare
        </span>
        <span class="{{ $tag }} {{ $blocked ? 'border-amber-700 text-amber-200' : 'border-ink-700 text-ink-400' }}">{{ $blocked ? 'necessário' : 'recomendado' }}</span>
    </summary>

    <div class="space-y-4 border-t {{ $blocked ? 'border-amber-900/60' : 'border-ink-800' }} px-4 py-4 text-sm text-ink-200">
        <p>
            @if ($blocked)
                O desafio anti-robô da Cloudflare barrou parte da leitura, então a descoberta não viu tudo o que vocês usam.
                Uma regra de 2 minutos no painel resolve.
            @else
                {{ $domain->domain }} está atrás da Cloudflare. Se o desafio anti-robô dela barrar alguma página que a descoberta lê
                (é comum no blog), uma regra no painel evita o problema de antemão.
            @endif
        </p>

        <ol class="list-decimal space-y-3 pl-5 marker:text-ink-400">
            <li>
                Entre em <a href="https://dash.cloudflare.com" target="_blank" rel="noopener" class="text-accent hover:underline">dash.cloudflare.com</a>
                e abra o domínio <b class="font-mono text-white">{{ $domain->domain }}</b>.
            </li>
            <li>
                No menu, vá em <b class="text-white">Security → WAF</b>, aba <b class="text-white">Custom rules</b>
                (no painel novo: <b class="text-white">Security → Security rules</b>), e clique em <b class="text-white">Create rule</b>.
            </li>
            <li>Em <b class="text-white">Rule name</b>, escreva <span class="font-mono text-white">Liberar SecurityMonitorBot</span>.</li>
            <li>
                Em <b class="text-white">If incoming requests match…</b>, clique em <b class="text-white">Edit expression</b> e cole:
                <div class="mt-2 flex flex-wrap items-start gap-2">
                    <textarea id="cf-expression" readonly rows="3" aria-label="Expressão da regra"
                              class="min-w-0 flex-[1_1_320px] resize-none rounded-md border border-ink-700 bg-ink-950 px-3 py-2 font-mono text-xs text-white focus:outline-none">{{ $domain->cloudflareExpression() }}</textarea>
                    <button type="button" data-copy="#cf-expression"
                            class="min-h-9 rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-accent">Copiar</button>
                </div>
            </li>
            <li>
                Em <b class="text-white">Then take action…</b>, escolha <b class="text-white">Skip</b> e marque
                <b class="text-white">All remaining custom rules</b>. Em <b class="text-white">More components to skip</b>, marque também
                <b class="text-white">Security Level</b> e <b class="text-white">Browser Integrity Check</b>; se aparecer
                <b class="text-white">All Super Bot Fight Mode rules</b>, marque.
            </li>
            <li>
                Em <b class="text-white">Place at</b>, escolha <b class="text-white">First</b> (a regra Skip só pula o que vem depois dela)
                e clique em <b class="text-white">Deploy</b>.
            </li>
            <li>
                @if ($blocked)
                    Volte aqui e clique em <b class="text-white">Ler de novo as páginas barradas</b>.
                @else
                    Pronto: a próxima varredura mensal{{ $domain->nextScanAt() ? ' ('.$domain->nextScanAt()->timezone('America/Sao_Paulo')->format('d/m').')' : '' }} já passa pela regra.
                @endif
            </li>
        </ol>

        <p class="text-xs text-ink-400">
            A regra vale só para os endereços que a descoberta lê (a página inicial, o blog e o endereço inexistente /secmon-check-…){{ $ips ? ' e só para os IPs do Security Monitor' : '' }}:
            o resto do site continua com todas as proteções.{{ $ips ? '' : ' Qualquer um pode se identificar como SecurityMonitorBot, por isso o escopo é tão estreito.' }}
            Se o desafio vier do <b class="text-ink-200">Bot Fight Mode</b> (plano Free), nenhuma regra consegue pulá-lo: é preciso desligá-lo em
            Security → Bots.
        </p>

        @include('alerts.partials.rerun')
    </div>
</details>
