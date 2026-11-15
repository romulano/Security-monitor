{{--
    O cartão "Testes de segurança" da Integração (Integracao): com PENTEST_ALLOWED, para toda conta. Sem a
    liberação do admin, a pílula diz "não liberado" e o botão leva à página que diz como pedir.
    Espera $card (PentestPanel::card()), $pills, $primary e $button da página.
--}}
<section aria-labelledby="testes-titulo" class="flex flex-wrap gap-6 rounded-xl border border-ink-800 bg-ink-900 p-5" data-source="tests">
    <div class="flex min-w-0 flex-[1_1_420px] flex-col gap-4">
        <div class="flex items-center gap-3">
            <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-lime-300/10 text-lime-300" aria-hidden="true">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
            </span>
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="testes-titulo" class="text-lg font-semibold text-white">
                    @if ($card['domains']->isNotEmpty())
                        <a href="{{ route('alerts.tests') }}" class="hover:text-accent">Testes de segurança</a>
                    @else
                        Testes de segurança
                    @endif
                </h2>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $pills[$card['pill'][1]][0] }}"><span class="size-1.5 rounded-full {{ $pills[$card['pill'][1]][1] }}"></span>{{ $card['pill'][0] }}</span>
                    <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] tracking-wider text-ink-200 uppercase">ativo · só com a sua autorização</span>
                </div>
            </div>
        </div>
        <p class="text-sm text-ink-200">
            Com a sua autorização, o Security Monitor testa o seu site do jeito que um atacante olharia, mas sem causar dano: descobre o que está exposto,
            levanta hipóteses, testa, confirma cada achado e entrega o relatório com a correção.
        </p>
        <ul class="list-disc space-y-0.5 pl-5 text-sm text-ink-400">
            <li>Só no domínio comprovado pelo TXT, o mesmo do Radar, conferido de novo durante o teste</li>
            <li>Você escolhe quando; parar é um clique, a qualquer momento, e tudo para em segundos</li>
            <li>Nada destrutivo: sem login, sem formulário, sem força bruta, sem sobrecarga</li>
        </ul>
        @if ($card['domains']->isEmpty())
            @include('alerts.tests.partials.start')
        @else
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('alerts.tests') }}" class="{{ $primary }}">Abrir os testes</a>
                @if ($card['draft'])
                    @php $step = \App\Services\Pentest\PentestWizard::reachable($card['draft']); @endphp
                    <a href="{{ route('alerts.tests.authorize', ['passo' => $step, 'dominio' => $card['draft']->host]) }}" class="{{ $button }} text-accent hover:underline">
                        Continuar {{ $card['draft']->host }} (passo {{ $step }})
                    </a>
                @endif
            </div>
        @endif
    </div>
    <div class="flex min-w-0 flex-[1_1_380px] flex-col gap-3">
        <p class="text-[11px] tracking-[0.1em] text-ink-400 uppercase">As 7 fases, sozinhas, dentro do que você autorizar</p>
        @include('alerts.tests.partials.phases', ['short' => true])
    </div>
</section>
