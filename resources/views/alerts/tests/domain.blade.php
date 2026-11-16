@extends('layouts.app')

@section('title', 'Autorização · '.$domain->host.' · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Services\Pentest\PentestBudget;
    use App\Services\Pentest\PentestDomainPage;
    use App\Services\Pentest\PentestRunView;
    use App\Services\Pentest\PentestWindow;

    $rule = $consent->rule;
    $tz = 'America/Sao_Paulo';
    $box = 'space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5';
    $title = 'text-[15px] font-semibold text-white';
    $contact = config('secmon.contact_email');
    $until = \Illuminate\Support\Carbon::parse($rule['window']['valid_until'])->format('d/m');
    // O estado escrito (nunca só na cor) e a caixa de aviso de cada um.
    [$label, $tone] = match ($state) {
        'authorized' => ['ativa', 'ok'],
        'expired' => ['vencida', 'neutro'],
        'suspended' => ['bloqueada pelo Security Monitor', 'erro'],
        default => ['revogada', 'neutro'],
    };
    // Revogada, nada mais sai desta autorização: sem "Mudar" e sem "Copiar de novo" (a faixa leva a autorizar de novo).
    // Bloqueada, sem "Mudar".
    $changeable = ! in_array($state, ['revoked', 'suspended'], true);
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                @include('alerts.partials.crumbs', ['parent' => ['Testes de segurança', route('alerts.tests')], 'here' => $domain->host])
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-semibold text-white">Autorização · <span class="inline-block max-w-full font-mono text-lg [overflow-wrap:anywhere]">{{ $domain->host }}</span></h1>
                    @include('alerts.tests.partials.pill', ['pill' => [$label, $tone]])
                </div>
                <p class="text-sm text-ink-400">
                    Termo {{ $consent->version }} aceito em {{ $consent->accepted_at->timezone($tz)->format('d/m') }} · {{ match (true) {
                        $consent->revoked_at !== null => 'revogada em '.$consent->revoked_at->timezone($tz)->format('d/m'),
                        $state === 'expired' => 'venceu em '.$until,
                        default => 'válida até '.$until,
                    } }}
                </p>
            </div>
            @if ($current)
                <form method="POST" action="{{ route('alerts.tests.domains.revoke', ['dominio' => $domain->host]) }}" data-confirm-ok="Revogar"
                      data-confirm="Revogar a autorização de {{ $domain->host }}? Qualquer teste em andamento para em até 30 segundos, e nenhum teste novo começa sem um aceite novo. A auditoria fica como prova.">
                    @csrf
                    @method('DELETE')
                    <button class="inline-flex min-h-11 items-center rounded-lg border border-red-900 px-4 text-sm font-semibold text-red-300 hover:border-red-400">Revogar a autorização</button>
                </form>
            @endif
        </div>

        @switch($state)
            @case('suspended')
                <section role="alert" class="space-y-1.5 rounded-xl border border-red-900 bg-red-950/40 px-4 py-3.5 text-sm">
                    <p class="font-semibold text-white">Os testes em {{ $domain->host }} estão bloqueados pelo Security Monitor.</p>
                    <p class="text-red-100">Nenhum teste começa até a análise terminar. Fale com {{ $contact }}.</p>
                </section>
                @break
            @case('expired')
                <section class="space-y-1.5 rounded-xl border border-ink-700 bg-ink-850 px-4 py-3.5 text-sm">
                    <p class="font-semibold text-white">A janela venceu em {{ $until }}.</p>
                    <p class="text-ink-200">Para testar de novo, escolha uma janela nova: isso pede um aceite novo.</p>
                    <a href="{{ route('alerts.tests.authorize', ['passo' => 4, 'dominio' => $domain->host]) }}" class="inline-flex min-h-11 items-center font-semibold text-accent hover:underline">Escolher a janela</a>
                </section>
                @break
            @case('revoked')
                <section class="space-y-1.5 rounded-xl border border-ink-700 bg-ink-850 px-4 py-3.5 text-sm">
                    <p class="font-semibold text-white">Revogada em {{ $consent->revoked_at->timezone($tz)->format('d/m \à\s H:i') }}{{ $consent->revoked_by === 'admin' ? ' pelo Security Monitor' : '' }}.</p>
                    <p class="text-ink-200">Nenhuma execução começa sem um aceite novo: refaça os passos e aceite o termo. A auditoria fica como prova do que foi autorizado.</p>
                    <a href="{{ route('alerts.tests.authorize', ['passo' => 1, 'dominio' => $domain->host]) }}" class="inline-flex min-h-11 items-center font-semibold text-accent hover:underline">Autorizar de novo</a>
                </section>
                @break
        @endswitch

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="space-y-4">
                <section class="{{ $box }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="{{ $title }}">Responsabilidade</h2>
                        @include('alerts.tests.partials.pill', ['pill' => ['confirmada', 'ok']])
                    </div>
                    <p class="text-sm text-ink-200">
                        Instalação de uso próprio, de um dono só: não há prova técnica de posse por registro TXT no DNS.
                        Você confirmou, no passo 2, em {{ \Illuminate\Support\Carbon::parse($domain->draft['responsibility_ack']['at'] ?? $consent->accepted_at)->timezone($tz)->format('d/m \à\s H:i') }}, que <span class="font-mono">{{ $domain->host }}</span> é seu.
                    </p>
                </section>

                <section class="{{ $box }}">
                    <h2 class="{{ $title }}">Termo aceito</h2>
                    <dl class="grid grid-cols-[110px_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
                        <dt class="text-ink-400">Versão</dt><dd class="text-white">{{ $consent->version }}</dd>
                        <dt class="text-ink-400">Quem</dt><dd class="break-words text-white">{{ $consent->user?->name }} · {{ $consent->user?->email }}</dd>
                        <dt class="text-ink-400">Quando</dt>
                        <dd class="text-white">{{ $consent->accepted_at->timezone($tz)->format('d/m \à\s H:i') }}{{ $consent->accepted_ip ? ', do IP '.$consent->accepted_ip : '' }}</dd>
                        <dt class="text-ink-400">Confirmação</dt><dd class="text-white">{{ PentestDomainPage::CONFIRMATIONS[$consent->reauth_method] ?? $consent->reauth_method }}</dd>
                        <dt class="text-ink-400">Declarações</dt><dd class="text-white">as {{ count($consent->declarations) }} marcadas</dd>
                        <dt class="text-ink-400">Impressão digital</dt>
                        <dd class="font-mono text-xs leading-5 [overflow-wrap:anywhere] text-white">sha256 {{ $consent->rendered_sha256 }}</dd>
                    </dl>
                    <a href="{{ route('alerts.tests.domains.consent', ['dominio' => $domain->host]) }}" class="inline-flex min-h-11 items-center text-sm text-accent hover:underline">Ver o texto aceito</a>
                </section>
            </div>

            <div class="space-y-4">
                <section class="{{ $box }}">
                    <h2 class="{{ $title }}">Regra de escopo</h2>
                    <p class="text-sm text-ink-200">
                        <span class="font-mono">{{ PentestRunView::name($domain->host) }}</span> e <span class="font-mono">{{ PentestRunView::name('*.'.$domain->host) }}</span> que forem seus.
                        @if ($rule['exclusions']) Nunca testar: <span class="font-mono">{!! collect($rule['exclusions'])->map(fn ($name) => PentestRunView::name($name)->toHtml())->join(', ') !!}</span>. @endif
                    </p>
                    <p class="text-xs text-ink-400">A lista exata de alvos sai em cada execução, no inventário, com o motivo de cada um que ficou de fora. Ampliar a regra pede um aceite novo.</p>
                </section>

                <section class="{{ $box }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="{{ $title }}">Janela e intensidade</h2>
                        @if ($changeable)
                            <a href="{{ route('alerts.tests.authorize', ['passo' => 4, 'dominio' => $domain->host]) }}" class="inline-flex min-h-11 items-center text-sm text-accent hover:underline" aria-label="Mudar a janela e a intensidade">Mudar</a>
                        @endif
                    </div>
                    <dl class="grid grid-cols-[110px_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
                        <dt class="text-ink-400">Quando</dt><dd class="text-white">{{ PentestWindow::describe($rule['window']) }}</dd>
                        <dt class="text-ink-400">Intensidade</dt>
                        <dd class="text-white">{{ PentestBudget::PRESETS[$rule['intensity']]['label'] ?? $rule['intensity'] }}: até {{ $values['rps'] }} req/s por site ({{ $values['rps_compartilhado'] }} em hospedagem compartilhada), {{ $values['max_requisicoes'] }} requisições e {{ $values['duracao'] }} de atividade</dd>
                        <dt class="text-ink-400">Firewall</dt><dd class="text-white">{{ $rule['waf'] === 'release' ? 'liberação restrita' : 'ligado' }}</dd>
                    </dl>
                    @if ($changeable)
                        <p class="text-xs text-ink-400">Mudar pede um novo aceite: o termo mostra os números novos.</p>
                    @endif
                </section>

                <section class="{{ $box }}">
                    <h2 class="{{ $title }}">Aviso ao time</h2>
                    <p class="text-sm text-ink-200">
                        {{ $noticeAt ? 'Confirmado em '.$noticeAt->timezone($tz)->format('d/m \à\s H:i').'.' : 'Confirmado antes do aceite.' }}
                        @if ($state !== 'revoked')
                            O texto sai com o número da próxima execução, para o SOC procurar no WAF.
                        @endif
                    </p>
                    @if ($state !== 'revoked')
                        <textarea id="aviso-dominio" readonly class="sr-only" tabindex="-1" aria-hidden="true">{{ $notice }}</textarea>
                        <button type="button" data-copy="#aviso-dominio" class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400">Copiar de novo</button>
                    @endif
                </section>
            </div>
        </div>

        <section id="nova-execucao" aria-labelledby="execucoes" class="{{ $box }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="execucoes" class="{{ $title }}">Execuções desta autorização</h2>
            </div>

            @if ($runs->isEmpty())
                <p class="text-sm text-ink-400">Nenhuma execução ainda.</p>
            @else
                <ol class="divide-y divide-ink-800 text-sm">
                    @foreach ($runs as $item)
                        <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
                            <span class="w-24 shrink-0 font-mono text-xs text-ink-400">{{ $item['when'] }}</span>
                            <a href="{{ route('alerts.tests.runs.show', ['execucao' => $item['run']->uuid]) }}" class="font-mono text-accent hover:underline">#{{ $item['run']->shortId() }}</a>
                            @include('alerts.tests.partials.pill', ['pill' => $item['pill']])
                            <span class="ml-auto text-xs text-ink-400">{{ $item['activity'] }} de atividade</span>
                        </li>
                    @endforeach
                </ol>
            @endif

            {{-- Nova execução: com a chave ligada, a liberação da conta, o aceite em vigor e sem outra ativa. --}}
            @switch($runInfo['start'])
                @case('form')
                    <div class="space-y-3 border-t border-ink-800 pt-3">
                        <h3 class="text-sm font-semibold text-white">Nova execução</h3>
                        @if ($opening)
                            <p class="text-sm text-ink-200">
                                Começa {{ $opening->isPast() || $opening->lte(now()->addMinute()) ? 'na janela de agora' : 'na próxima abertura: '.\App\Services\Pentest\PentestRunView::when($opening, now(), $consent->rule['window']['tz']).' ('.\App\Services\Pentest\PentestWindow::ZONES[$consent->rule['window']['tz']].')' }}.
                                Um teste típico leva de 30 a 40 minutos.
                            </p>
                            <form method="POST" action="{{ route('alerts.tests.runs.store', ['dominio' => $domain->host]) }}" class="space-y-2"
                                  data-confirm="Agendar uma nova execução em {{ $domain->host }}? Ela segue a regra aceita: {{ \App\Services\Pentest\PentestWindow::describe($consent->rule['window']) }}." data-confirm-ok="Agendar">
                                @csrf
                                <button class="inline-flex min-h-11 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Agendar nova execução</button>
                            </form>
                        @else
                            <p class="text-sm text-ink-200">A validade desta autorização terminou: escolha uma nova janela para testar de novo.</p>
                        @endif
                    </div>
                    @break
            @endswitch
        </section>

        <section aria-labelledby="chaves" class="{{ $box }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="chaves" class="{{ $title }}">Chaves no site</h2>
                @if ($keyCounts['total'] > 0)
                    <a href="{{ route('alerts.keys.domain', ['dominio' => $domain->host]) }}" class="inline-flex min-h-11 items-center text-sm text-accent hover:underline">Ver as chaves</a>
                @endif
            </div>
            @if ($keyCounts['total'] === 0)
                <p class="text-sm text-ink-400">Nenhuma chave achada. Em cada execução, a navegação procura chaves de API, tokens e senhas nas páginas e nos scripts do site.</p>
            @else
                @php
                    $leakText = $keyCounts['leak'].($keyCounts['leak'] === 1 ? ' que não deveria estar exposta' : ' que não deveriam estar expostas');
                    $allowedText = $keyCounts['allowed'].($keyCounts['allowed'] === 1 ? ' que pode ficar' : ' que podem ficar');
                @endphp
                <p class="text-sm text-ink-200">{{ $keyCounts['total'] }} {{ $keyCounts['total'] === 1 ? 'chave achada' : 'chaves achadas' }}: @if ($keyCounts['open'] > 0)<b class="text-white">{{ $keyCounts['open'] }} para você decidir</b>, @endif{{ $leakText }} e {{ $allowedText }}.</p>
            @endif
        </section>

        <section aria-labelledby="registro" class="{{ $box }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="registro" class="{{ $title }}">Registro da autorização</h2>
                <span class="text-xs text-ink-400">só leitura · o recorte da auditoria que a sua conta vê</span>
            </div>
            <ol class="divide-y divide-ink-800 text-sm">
                @foreach ($log as $entry)
                    <li class="flex flex-wrap gap-x-4 gap-y-0.5 py-2">
                        <span class="w-24 shrink-0 font-mono text-xs leading-5 text-ink-400">{{ $entry['at']->timezone($tz)->format('d/m H:i') }}</span>
                        <span class="min-w-0 flex-1 text-ink-200">{{ $entry['text'] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
@endsection
