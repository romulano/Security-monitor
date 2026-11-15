{{--
    O formato do webhook: como o Security Monitor chama, o JSON (exemplo copiável), os eventos, a conferência da
    assinatura em PHP, Node.js e Python, e as novas tentativas. Recebe $example (JSON) e $events.
--}}
@php
    $code = 'w-full resize-y rounded-lg border border-ink-800 bg-ink-950 p-3 font-mono text-xs leading-relaxed text-ink-200 focus:border-accent focus:outline-none';
    $copy = 'min-h-9 shrink-0 rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-accent';
    $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'User-Agent' => (string) config('secmon.crawler.user_agent'),
        'Security-Monitor-Event' => 'o evento, como no campo event',
        'Security-Monitor-Delivery' => 'o id da entrega, o mesmo do campo id',
        'Security-Monitor-Notification' => 'o segredo do webhook (com a autenticação ligada)',
        'Security-Monitor-Signature' => 't=<unix>,v1=<HMAC-SHA256 de "<t>.<corpo>"> com o mesmo segredo (com a autenticação ligada)',
    ];
@endphp
<section id="formato" class="space-y-5 rounded-xl border border-ink-800 bg-ink-900 p-5">
    <div>
        <h2 class="text-lg font-semibold text-white">Como o Security Monitor chama o seu endereço</h2>
        <p class="text-sm text-ink-400">
            Um <span class="font-mono text-ink-200">POST</span> por notificação, com o JSON no corpo. Responda com 2xx em até
            {{ (int) config('secmon.alerts.webhook.timeout', 10) }} segundos: o mais rápido é guardar a notificação e tratar depois.
        </p>
    </div>

    <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
        @foreach ($headers as $name => $about)
            <dt class="font-mono text-ink-200">{{ $name }}</dt>
            <dd class="min-w-0 text-ink-400 [overflow-wrap:anywhere]">{{ $about }}</dd>
        @endforeach
    </dl>

    <div class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-white">Exemplo: <span class="font-mono text-sm text-amber-200">alert.match</span></h3>
            <button type="button" data-copy="#webhook-example" class="{{ $copy }}">Copiar</button>
        </div>
        <textarea id="webhook-example" readonly rows="18" spellcheck="false" class="{{ $code }}">{{ $example }}</textarea>
        <p class="text-xs text-ink-400">
            Leia os campos pelo nome: a ordem das chaves pode variar, e campos novos podem aparecer na mesma versão.
            Uma mudança que quebre o formato sai como <span class="font-mono">"version": 2</span>.
        </p>
    </div>

    <div class="space-y-2">
        <h3 class="font-semibold text-white">Eventos</h3>
        <ul class="space-y-1 text-sm text-ink-200">
            @foreach ($events as $event => $about)
                <li><span class="font-mono text-amber-200">{{ $event }}</span> <span class="text-ink-400">· {{ $about }}</span></li>
            @endforeach
        </ul>
        <p class="text-xs text-ink-400">
            Códigos de confirmação (a reautenticação e o aceite dos testes de segurança) nunca saem por webhook: só numa conversa
            privada do Telegram ou numa mensagem direta do Discord, que só a pessoa lê.
        </p>
    </div>

    <div class="space-y-2">
        <h3 class="font-semibold text-white">Confira que veio do Security Monitor</h3>
        <p class="text-sm text-ink-200">
            Refaça o HMAC-SHA256 de <span class="font-mono">"&lt;t&gt;.&lt;corpo&gt;"</span> com o corpo exato que chegou e o seu segredo,
            compare em tempo constante e recuse o <span class="font-mono">t</span> com mais de 5 minutos de diferença do seu relógio
            (é o que barra a repetição de um envio antigo). Confira também o <span class="font-mono">Security-Monitor-Notification</span>.
            Guarde o <span class="font-mono">id</span>: uma nova tentativa repete o mesmo.
        </p>
        @foreach (\App\Services\Alerts\Webhook\WebhookExample::verification() as $language => $snippet)
            <details class="rounded-lg border border-ink-800 bg-ink-950/40">
                <summary class="cursor-pointer px-3 py-2.5 text-sm text-ink-200">{{ $language }}</summary>
                <div class="space-y-2 border-t border-ink-800 p-3">
                    <div class="flex justify-end">
                        <button type="button" data-copy="#webhook-code-{{ $loop->index }}" class="{{ $copy }}">Copiar</button>
                    </div>
                    <textarea id="webhook-code-{{ $loop->index }}" readonly rows="{{ min(30, substr_count($snippet, "\n") + 1) }}" spellcheck="false" class="{{ $code }}">{{ $snippet }}</textarea>
                </div>
            </details>
        @endforeach
    </div>

    <div class="space-y-2">
        <h3 class="font-semibold text-white">Entregas e novas tentativas</h3>
        <ul class="list-disc space-y-1 pl-5 text-sm text-ink-200">
            <li>2xx: entregue. O corpo da resposta é descartado.</li>
            <li>5xx, tempo esgotado ou falha de rede: novas tentativas em 1, 5 e 30 minutos, com o mesmo <span class="font-mono">id</span>.</li>
            <li>429: o Security Monitor espera o <span class="font-mono">Retry-After</span> (até 30 minutos).</li>
            <li>Os outros 4xx e os redirecionamentos (3xx): sem nova tentativa. O Security Monitor não segue redirecionamentos.</li>
            <li>{{ (int) config('secmon.alerts.webhook.max_failures', 5) }} entregas seguidas com falha: o envio para e avisamos por e-mail. Um teste que der certo põe o webhook de volta.</li>
            <li>Só https, na porta 443, num domínio comprovado {{ auth()->user()?->isCompanyAccount() ? 'pela empresa' : 'pela sua conta' }}. O Security Monitor nunca chama endereço de rede interna.</li>
        </ul>
    </div>
</section>
