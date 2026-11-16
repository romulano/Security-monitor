{{--
    Passo 7 (Autorizar-7): o resumo do que a pessoa autoriza, o que ainda falta e o "Autorizar".
--}}
@php
    use App\Services\Pentest\PentestBudget;
    use App\Services\Pentest\PentestRunView;
    use App\Services\Pentest\PentestTerms;
    use App\Services\Pentest\PentestWindow;

    // Os "Editar" do resumo têm 44 px de toque (Designer, P6c); o "Ir ao passo" do aviso fica no meio do texto, sem quebrar.
    $edit = fn (int $n, string $label = 'Editar', ?string $aria = null, string $class = 'inline-flex min-h-11 items-center') => '<a href="'.e(route('alerts.tests.authorize', ['passo' => $n, 'dominio' => $domain->host])).'"'
        .($aria ? ' aria-label="'.e($aria).'"' : '').' class="'.$class.' text-accent hover:underline">'.$label.'</a>';
    // Num leitor de tela, cada "Editar" diz o que edita.
    $labels = ['Responsabilidade' => 'Editar a confirmação de responsabilidade', 'Escopo' => 'Editar o escopo', 'Janela' => 'Editar a janela', 'Intensidade' => 'Editar a intensidade',
        'Firewall' => 'Editar como o teste vê o site', 'Aviso ao time' => 'Editar o aviso ao time', 'Termo' => 'Ver o termo'];
    $time = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m \à\s H:i');
@endphp

<div>
    <h2 class="text-lg font-semibold text-white">Confira e agende</h2>
    <p class="text-sm text-ink-400">Este é o resumo do que você autoriza. Para mudar alguma coisa, volte ao passo pelo “Editar”.</p>
</div>

@if (! $preview)
    <section class="space-y-1 rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4 text-sm text-amber-100">
        <p>{{ $ruleError }}</p>
        <p>
            A autorização sai com os números do passo 4.
            <a href="{{ route('alerts.tests.authorize', ['passo' => 4, 'dominio' => $domain->host]) }}" class="font-semibold underline">Volte ao passo 4</a> e grave a janela de novo.
        </p>
    </section>
@else
    @php
        $copy = $domain->domain;
        $window = $rule['window'];
        $budget = $rule['budget'];
        $hash = $preview['rendered_sha256'];
        $rows = [
            ['Domínio', PentestRunView::name($domain->host)->toHtml(), null],
            ['Responsabilidade', $domain->responsibilityConfirmed()
                ? 'você confirmou, em '.e(\Illuminate\Support\Carbon::parse($domain->draft['responsibility_ack']['at'])->timezone('America/Sao_Paulo')->format('d/m \à\s H:i')).', que o domínio é seu'
                : 'falta confirmar que o domínio é seu', 2],
            ['Escopo', PentestRunView::name($domain->host)->toHtml().' e '.PentestRunView::name('*.'.$domain->host)->toHtml()
                .($rule['exclusions'] ? ', menos '.collect($rule['exclusions'])->map(fn ($name) => PentestRunView::name($name)->toHtml())->join(', ') : '')
                .'. Serviços de outras empresas: só o DNS é lido. A lista de alvos fecha no início do teste.', 3],
            ['Janela', e(ucfirst(PentestWindow::describe($window))).'. '
                .($window['start_mode'] === 'date' ? 'Começa a partir de '.e(\Illuminate\Support\Carbon::parse($window['valid_from'])->format('d/m')).'.' : 'Começa na próxima janela.')
                .' Até '.e($values['duracao']).' de atividade; um teste típico leva de 30 a 40 minutos.', 4],
            ['Intensidade', e(PentestBudget::PRESETS[$rule['intensity']]['label']).'. Testes a até '.e($values['rps']).' requisições por segundo por site ('.e($values['rps_compartilhado']).' em hospedagem compartilhada), mapeamento a '.e($values['rps_mapeamento']).', portas a '.e($values['portas_cps']).' conexões por segundo por IP. No máximo '.e($values['max_requisicoes']).' requisições.', 4],
            ['Firewall', $rule['waf'] === 'release'
                ? 'Liberação restrita. O seu time libera só o IP do Security Monitor com o cabeçalho deste teste, só durante a janela.'
                : 'Ligado. O teste vê o que qualquer visitante vê; o que o firewall barrar aparece no relatório como não testado.', 4],
            ['Aviso ao time', $noticeAt && ! isset($missing[5]) ? 'Você confirmou em '.e($time($noticeAt)).' que avisou o time.' : 'falta confirmar para esta regra', 5],
            ['Termo', e(PentestTerms::TERM).' · '.($declaredAt && ! isset($missing[6]) ? count($preview['declarations']).' declarações marcadas em '.e($time($declaredAt)) : 'declarações por marcar')
                .' · impressão digital <span class="font-mono" title="sha256 '.e($hash).'">sha256 '.e(substr($hash, 0, 4)).'…'.e(substr($hash, -2)).'</span>', 6],
        ];
    @endphp

    <section class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
        <h3 class="text-[15px] font-semibold text-white">Resumo da autorização</h3>
        <dl class="divide-y divide-ink-800 text-sm">
            @foreach ($rows as [$label, $html, $step])
                <div class="grid gap-1 py-2.5 sm:grid-cols-[140px_minmax(0,1fr)_auto] sm:gap-4">
                    <dt class="text-ink-400">{{ $label }}</dt>
                    <dd class="text-ink-200">{!! $html !!}</dd>
                    <dd>@if ($step){!! $edit($step, $step === 6 ? 'Ver' : 'Editar', $labels[$label] ?? null) !!}@endif</dd>
                </div>
            @endforeach
            <div class="grid gap-1 py-2.5 sm:grid-cols-[140px_minmax(0,1fr)_auto] sm:gap-4">
                <dt class="text-ink-400">Onde avisar</dt>
                <dd class="text-ink-200">
                    {{ $channels->isEmpty() ? 'nenhum app conectado: os avisos ficam na sua conta.' : $channels->map(fn ($c) => $c->appName().' ('.$c->label().')')->join(' · ') }}
                </dd>
                <dd><a href="{{ route('alerts.notifiers') }}" class="text-accent hover:underline">Notificadores</a></dd>
            </div>
        </dl>
    </section>

    @if ($missing)
        <section class="space-y-2 rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4">
            <h3 class="text-sm font-semibold text-white">Antes de autorizar</h3>
            <ul class="space-y-1.5 text-sm text-amber-100">
                @foreach ($missing as $step => $text)
                    <li>{{ PentestRunView::withDomain($text, $domain->host) }} {!! $edit($step, 'Ir ao passo '.$step, null, 'whitespace-nowrap') !!}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canAuthorize)
        <section class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
            <form id="step-form" method="POST" action="{{ route('alerts.tests.domains.accept', ['dominio' => $domain->host]) }}" class="space-y-2"
                  data-confirm="Autorizar e agendar testes em {{ $domain->host }}? Janela: {{ PentestWindow::describe($window) }}. Termo {{ PentestTerms::TERM }}. Você pode revogar a autorização a qualquer momento.">
                @csrf
                <input type="hidden" name="revogacao" value="{{ $revocation }}">
                @foreach (['revogacao', 'aviso', 'termo', 'declaracoes', 'inicio', 'fim', 'fuso', 'dias', 'validade', 'inicio_data', 'fim_de_semana', 'intensidade', 'firewall', 'exclusoes'] as $key)
                    @error($key)<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                @endforeach
            </form>
        </section>
    @endif
@endif
