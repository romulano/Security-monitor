@extends('layouts.app')

@section('title', 'Testes de segurança · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Services\Pentest\PentestAuthorization;
    use App\Services\Pentest\PentestWindow;
    use App\Services\Pentest\PentestWizard;

    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
    $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m');
    $at = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('H:i');
    // Estado da autorização => o tom da pílula, com o ponto (o texto vai sempre escrito, não só na cor).
    $tones = [
        'authorized' => 'ok',
        'draft' => 'neutro',
        'waiting_ownership' => 'alerta',
        'ownership_missing' => 'erro',
        'expired' => 'neutro',
        'revoked' => 'neutro',
        'suspended' => 'erro',
    ];
    $full = $domains->count() >= $max;
    $canAdd = ! $full;
    $contact = config('secmon.contact_email');
    // Revogar com execução (item 2 do Blue Team): o teste em andamento para, e nenhum novo começa.
    $revoke = fn (string $host) => "Revogar a autorização de {$host}? Qualquer teste em andamento para em até 30 segundos, e nenhum teste novo começa sem um aceite novo. A auditoria fica como prova.";
@endphp

@section('content')
    <div class="mx-auto max-w-6xl space-y-8">
        @if ($domains->isEmpty())
            <section aria-labelledby="convite" class="max-w-2xl space-y-3">
                @include('alerts.partials.crumbs', ['here' => 'Testes de segurança'])
                <h1 id="convite" class="text-2xl font-semibold text-white">Descubra as falhas do seu site antes de um atacante</h1>
                <p class="text-[15px] leading-6 text-ink-200">
                    Com a sua autorização, o Security Monitor faz um teste de segurança automático e não destrutivo no seu domínio, em 7 fases,
                    e entrega o relatório com a prova e a correção de cada achado.
                </p>
                <ol class="mt-2 space-y-2 text-sm text-ink-200">
                    @foreach ([
                        'Só no seu domínio: a mesma prova por registro TXT do Radar, conferida de novo durante o teste.',
                        'Você escolhe os dias, o horário e a intensidade. Parar é um clique, a qualquer momento, e tudo para em segundos.',
                        'Nada destrutivo: sem login, sem envio de formulários, sem força bruta e sem sobrecarga. O que o teste nunca faz está no termo, antes do aceite.',
                        'Cada achado é testado de novo, de outro jeito, antes de entrar no relatório.',
                        'A primeira execução de cada autorização passa pela aprovação de um analista do Security Monitor.',
                    ] as $i => $line)
                        <li class="flex gap-2"><span class="text-accent">{{ $i + 1 }}.</span><span>{{ $line }}</span></li>
                    @endforeach
                </ol>
                <div class="pt-2">@include('alerts.tests.partials.start')</div>
            </section>

            <section aria-labelledby="fases" class="space-y-4">
                <div class="flex items-baseline gap-3.5">
                    <h2 id="fases" class="text-[11px] tracking-[0.1em] text-accent uppercase">As 7 fases</h2>
                    <span aria-hidden="true" class="h-px flex-1 bg-ink-800"></span>
                </div>
                @include('alerts.tests.partials.phases')
            </section>
        @else
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="max-w-3xl">
                    @include('alerts.partials.crumbs', ['here' => 'Testes de segurança'])
                    <h1 class="text-xl font-semibold text-white">Testes de segurança</h1>
                    <p class="text-sm text-ink-400">
                        Os domínios que você autorizou e cada execução, com o relatório. Só testamos domínio comprovado pelo TXT e dentro da janela que você escolheu.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($canAdd)
                        <a href="{{ route('alerts.tests.authorize', ['passo' => 1]) }}" class="{{ $button }} {{ $now->isEmpty() ? 'bg-accent font-semibold text-ink-950 hover:brightness-110' : 'border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white' }}">
                            Autorizar outro domínio
                        </a>
                    @endif
                    <span class="text-xs text-ink-400">{{ $domains->count() }} de {{ $max }} domínios{{ $full ? ' · exclua um para autorizar outro' : '' }} · {{ $week['count'] }} de {{ $week['max'] }} execuções nesta semana</span>
                </div>
            </div>

            @if ($now->isNotEmpty())
                <section aria-labelledby="agora" class="space-y-3">
                    <h2 id="agora" class="text-base font-semibold text-white">Agora</h2>
                    @foreach ($now as $item)
                        @php $run = $item['run']; @endphp
                        <article class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-accent/40 bg-ink-900 p-5" data-run="{{ $run->uuid }}">
                            <div class="min-w-0 space-y-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-mono text-[15px] text-white">{{ $run->domain }}</h3>
                                    @include('alerts.tests.partials.pill', ['pill' => $item['pill'], 'pulse' => in_array($run->status, ['running', 'stopping'], true)])
                                    <span class="font-mono text-xs text-ink-400">#{{ $run->shortId() }}</span>
                                </div>
                                <p class="text-sm text-ink-400">{{ $item['subtitle'] }} · teste típico de 30 a 40 minutos</p>
                                <p class="text-sm text-ink-200">Orçamento: {{ $item['requests'] }}</p>
                            </div>
                            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                                <a href="{{ route('alerts.tests.runs.show', ['execucao' => $run->uuid]) }}" class="{{ $button }} border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white">Acompanhar</a>
                                @if (in_array($run->status, ['running', 'paused'], true))
                                    @include('alerts.tests.partials.stop', ['form' => 'max-sm:flex-1', 'class' => 'w-full'])
                                @endif
                            </div>
                        </article>
                    @endforeach
                </section>
            @endif

            <section aria-labelledby="dominios" class="space-y-3">
                <h2 id="dominios" class="text-base font-semibold text-white">Domínios</h2>

                @foreach ($domains as $domain)
                    @php
                        $state = $states[$domain->id];
                        $consent = $domain->currentConsent;
                        $step = PentestWizard::reachable($domain);
                        $info = $runInfo[$domain->id];
                    @endphp
                    <article class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5" data-domain="{{ $domain->host }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="mr-1 max-w-full font-mono text-[15px] [overflow-wrap:anywhere] text-white">{{ $domain->host }}</h3>
                            @include('alerts.tests.partials.pill', ['pill' => [PentestAuthorization::STATES[$state], $tones[$state]]])
                            @if ($domain->ownershipHolds() && $domain->ownership_checked_at)
                                @include('alerts.tests.partials.pill', ['pill' => ['TXT no ar · conferido em '.$when($domain->ownership_checked_at).' às '.$at($domain->ownership_checked_at), 'ok']])
                            @elseif ($domain->ownership_status === 'unknown')
                                @include('alerts.tests.partials.pill', ['pill' => ['DNS sem resposta na última conferência', 'alerta']])
                            @endif
                            @if ($consent)
                                @include('alerts.tests.partials.pill', ['pill' => ['termo '.$consent->version.' aceito em '.$when($consent->accepted_at), 'neutro']])
                            @endif
                            @if ($info['approved'])
                                @include('alerts.tests.partials.pill', ['pill' => [$info['approved'], str_starts_with($info['approved'], '1ª execução aprovada') ? 'ok' : 'neutro']])
                            @endif
                        </div>

                        <p class="text-sm text-ink-200">
                            @switch($state)
                                @case('suspended')
                                    Bloqueado pelo Security Monitor em {{ $when($domain->suspended_at) }}. Nenhum teste começa até a análise terminar. Fale com {{ $contact }}.
                                    @break
                                @case('expired')
                                    Venceu em {{ \Illuminate\Support\Carbon::parse($consent->rule['window']['valid_until'])->format('d/m') }}. Para testar de novo, escolha uma janela nova e aceite o termo.
                                    @break
                                @case('waiting_ownership')
                                    Falta o registro TXT em {{ $domain->host }}. Dá para seguir os passos enquanto isso; só não dá para autorizar sem ele.
                                    @break
                                @case('ownership_missing')
                                    O registro TXT de {{ $domain->host }} não está mais no DNS. Publique de novo: sem ele, não dá para autorizar nem testar.
                                    @break
                                @case('authorized')
                                    Janela: {{ PentestWindow::describe($consent->rule['window']) }}.
                                    @break
                                @case('revoked')
                                    Revogada em {{ $when($domain->latestConsent?->revoked_at) }} às {{ $at($domain->latestConsent?->revoked_at) }}. Para testar de novo, refaça os passos e aceite o termo.
                                    @break
                                @default
                                    Rascunho no passo {{ $step }} de {{ PentestWizard::LAST_STEP }}, salvo em {{ $when($domain->updated_at) }} às {{ $at($domain->updated_at) }}.
                            @endswitch
                        </p>

                        @if ($info['active'] || $info['last'])
                            @php $shown = $info['active'] ?? $info['last']; @endphp
                            <p class="flex flex-wrap items-center gap-2 text-sm text-ink-200">
                                {{ $info['active'] ? 'Execução agora:' : 'Última execução:' }}
                                <a href="{{ route('alerts.tests.runs.show', ['execucao' => $shown['run']->uuid]) }}" class="font-mono text-accent hover:underline">#{{ $shown['run']->shortId() }}</a>
                                @include('alerts.tests.partials.pill', ['pill' => $shown['pill']])
                            </p>
                        @endif
                        @if ($info['start'] === 'limit')
                            <p class="text-sm text-ink-400">Nova execução: {{ lcfirst($info['refusal']) }}</p>
                        @endif

                        <div class="flex flex-wrap items-center gap-2">
                            @if ($info['start'] === 'form')
                                <a href="{{ route('alerts.tests.domains.show', ['dominio' => $domain->host]) }}#nova-execucao" class="{{ $button }} bg-accent font-semibold text-ink-950 hover:brightness-110">Nova execução</a>
                            @endif
                            @if ($domain->consents_exists)
                                <a href="{{ route('alerts.tests.domains.show', ['dominio' => $domain->host]) }}" class="{{ $button }} border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white">Ver autorização</a>
                            @endif
                            @unless ($state === 'suspended' || $consent)
                                <a href="{{ route('alerts.tests.authorize', ['passo' => $step, 'dominio' => $domain->host]) }}" class="{{ $button }} {{ $domain->consents_exists ? 'text-accent hover:underline' : 'border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white' }}">
                                    {{ $domain->consents_exists ? 'Autorizar de novo' : 'Continuar' }} (passo {{ $step }})
                                </a>
                            @endunless
                            @if (in_array($state, ['waiting_ownership', 'ownership_missing'], true) && ($consent || $step !== 2))
                                <a href="{{ route('alerts.tests.authorize', ['passo' => 2, 'dominio' => $domain->host]) }}" class="{{ $button }} text-accent hover:underline">Ver o registro TXT</a>
                            @endif
                            @if ($state === 'expired')
                                <a href="{{ route('alerts.tests.authorize', ['passo' => 4, 'dominio' => $domain->host]) }}" class="{{ $button }} text-accent hover:underline">Escolher a janela</a>
                            @endif
                            @if ($consent)
                                <form method="POST" action="{{ route('alerts.tests.domains.revoke', ['dominio' => $domain->host]) }}" data-confirm-ok="Revogar"
                                      data-confirm="{{ $revoke($domain->host) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="{{ $button }} text-red-300 hover:text-red-200">Revogar</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('alerts.tests.domains.destroy', ['dominio' => $domain->host]) }}" class="sm:ml-auto"
                                  data-confirm="Excluir {{ $domain->host }} dos testes de segurança? O rascunho e a cópia da posse saem daqui{{ $consent ? ', e a autorização é revogada' : '' }}. O Radar de domínios e Notícias continuam como estavam.">
                                @csrf
                                @method('DELETE')
                                <button class="{{ $button }} text-ink-400 hover:text-red-300">Excluir</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </section>
        @endif

        @if ($hasRuns)
            <section aria-labelledby="historico" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="historico" class="text-base font-semibold text-white">Histórico de execuções</h2>
                    {{-- Os filtros do histórico (Testes-lista): links comuns, sem JS. --}}
                    <nav aria-label="Filtrar o histórico" class="flex flex-wrap gap-1 text-sm">
                        @foreach (['' => 'Todas'] + array_map(fn ($filter) => $filter[0], \App\Services\Pentest\PentestPanel::HISTORY) as $key => $name)
                            <a href="{{ route('alerts.tests', array_filter(['situacao' => $key])) }}#historico" @if (($situation ?? '') === $key) aria-current="page" @endif
                               class="inline-flex min-h-11 items-center rounded-lg px-3 {{ ($situation ?? '') === $key ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-white' }}">{{ $name }}</a>
                        @endforeach
                    </nav>
                </div>
                @if ($history->isEmpty())
                    <p class="text-sm text-ink-400">Nenhuma execução nesta situação.</p>
                @else
                    <ol class="divide-y divide-ink-800 overflow-hidden rounded-xl border border-ink-800 bg-ink-900">
                        @foreach ($history as $item)
                            @php $run = $item['run']; @endphp
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-5 py-3 text-sm">
                                <span class="w-24 shrink-0 font-mono text-xs text-ink-400">{{ $item['when'] }}</span>
                                <a href="{{ route('alerts.tests.runs.show', ['execucao' => $run->uuid]) }}" class="font-mono text-accent hover:underline">#{{ $run->shortId() }}</a>
                                <span class="min-w-0 font-mono [overflow-wrap:anywhere] text-ink-200">{{ $run->domain }}</span>
                                @include('alerts.tests.partials.pill', ['pill' => $item['pill']])
                                <span class="text-xs text-ink-400 sm:ml-auto">{{ $item['activity'] }} de atividade · {{ $item['requests'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endif
    </div>
@endsection
