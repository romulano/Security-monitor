@extends('layouts.admin')

@section('title', 'Alertas')

@php
    $box = 'rounded-lg border border-ink-800 bg-ink-900';
    $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm';
@endphp

@section('content')
    @include('admin.alerts._nav')

    <section class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Alertas na hora', $health['realtime'], $health['realtime'] ? 'ligado' : 'desligado'],
            ['Resumo diário', $health['digest'], $health['digest'] ? 'ligado · '.$config['digest_default_time'] : 'desligado'],
            ['Descoberta por domínio', $health['discovery'], $health['discovery'] ? 'ligada' : 'desligada'],
            ['App do Slack (OAuth)', $health['oauth'], $health['oauth'] ? 'configurado no .env' : 'sem SLACK_CLIENT_ID/SECRET'],
            ['App do Discord (bot)', $health['discord'], $health['discord'] ? 'configurado no .env' : 'sem DISCORD_CLIENT_ID/SECRET/BOT_TOKEN'],
            ['Bot do Telegram', $health['telegram'], $health['telegram'] ? 'configurado no .env' : 'sem TELEGRAM_BOT_TOKEN'],
        ] as [$label, $ok, $detail])
            <div class="rounded-lg border p-4 {{ $ok ? 'border-emerald-900/60 bg-emerald-950/20' : 'border-red-900/60 bg-red-950/20' }}">
                <p class="mb-1 text-[11px] tracking-widest text-ink-400 uppercase">{{ $label }}</p>
                <p class="flex items-center gap-2 text-sm font-medium {{ $ok ? 'text-emerald-300' : 'text-red-300' }}">
                    <span class="size-2 rounded-full {{ $ok ? 'bg-emerald-400' : 'bg-red-400' }}"></span>{{ $detail }}
                </p>
            </div>
        @endforeach
    </section>

    <section class="mb-6 flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.alerts.run-match') }}">
            @csrf
            <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Rodar matching das últimas 24 h</button>
        </form>
        <form method="POST" action="{{ route('admin.alerts.send-digests') }}" data-confirm="Mandar agora o resumo de hoje para quem ainda não recebeu?">
            @csrf
            <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-ink-700">Enviar resumos de hoje agora</button>
        </form>
        <form method="POST" action="{{ route('admin.alerts.recompile') }}">
            @csrf
            <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-ink-700">Recompilar todos os perfis</button>
        </form>
        <a href="{{ route('admin.alerts.deliveries', ['status' => 'failed']) }}" class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-ink-700">Ver envios com falha</a>
    </section>

    <section class="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Perfis ativos', $stats['active'], $stats['paused'].' pausado(s)'],
            ['Entrevistas em rascunho', $stats['drafts'], $stats['stale_drafts'].' parada(s) há mais de 7 dias'],
            ['Canais ativos', $stats['channels'], ($stats['channels'] - $stats['channels_discord'] - $stats['channels_telegram'] - $stats['channels_webhook']).' Slack ('.$stats['channels_oauth'].' pelo app) · '.$stats['channels_discord'].' Discord · '.$stats['channels_telegram'].' Telegram · '.$stats['channels_webhook'].' webhook'],
            ['Canais com problema', $stats['broken'], 'precisam reconectar'],
            ['Alertas enviados', $stats['sent_24h'], 'últimas 24 h'],
            ['Resumos de hoje', $stats['digests_today'].' de '.$stats['digest_channels'], 'canais com resumo ligado'],
            ['Falhas de envio', $stats['failed_24h'], 'últimas 24 h'],
            ['Não relevantes', $stats['irrelevant_rate'].'%', 'dos alertas, 30 dias · '.$stats['ai_held_30d'].' segurado(s) pela IA'],
        ] as [$label, $value, $sub])
            <div class="{{ $box }} p-4">
                <p class="text-[11px] tracking-widest text-ink-400 uppercase">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-white">{{ $value }}</p>
                <p class="text-xs text-ink-400">{{ $sub }}</p>
            </div>
        @endforeach
    </section>

    <div class="mb-8 grid gap-4 lg:grid-cols-2">
        <section class="{{ $box }} overflow-hidden">
            <h2 class="border-b border-ink-800 px-4 py-3 text-sm font-semibold text-white">Pedem atenção</h2>
            @php $empty = $issues['broken']->isEmpty() && $issues['domains']->isEmpty() && $issues['drafts']->isEmpty(); @endphp
            @if ($empty)
                <p class="px-4 py-6 text-center text-sm text-ink-400">Nada pendente.</p>
            @endif
            @foreach ($issues['broken'] as $channel)
                <div class="flex flex-wrap items-center gap-3 border-t border-ink-850 px-4 py-3 first:border-t-0">
                    <span class="rounded border border-red-900 px-1.5 py-px text-[10px] font-semibold tracking-wider text-red-300 uppercase">canal</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-white">{{ $channel->user->alertProfile?->company_name ?: $channel->user->name }} · {{ $channel->appName() }}</p>
                        <p class="text-xs text-ink-400">{{ $channel->last_error }} · {{ $channel->broken_at?->timezone('America/Sao_Paulo')->format('d/m H:i') }}</p>
                    </div>
                    <a href="{{ route('alerts.notifiers') }}" class="text-xs text-accent hover:underline">Abrir notificadores</a>
                </div>
            @endforeach
            @foreach ($issues['domains'] as $domain)
                <div class="flex flex-wrap items-center gap-3 border-t border-ink-850 px-4 py-3 first:border-t-0">
                    <span class="rounded border border-orange-900 px-1.5 py-px text-[10px] font-semibold tracking-wider text-orange-300 uppercase">domínio</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-sm font-semibold text-white">{{ $domain->domain }}</p>
                        <p class="text-xs text-ink-400">{{ $domain->discovery_status }}: {{ $domain->last_error }}</p>
                    </div>
                    @if (Route::has('admin.alerts.domains'))
                        <a href="{{ route('admin.alerts.domains', ['log' => $domain->id]) }}" class="text-xs text-accent hover:underline">Ver log</a>
                    @endif
                </div>
            @endforeach
            @foreach ($issues['drafts'] as $draft)
                <div class="flex flex-wrap items-center gap-3 border-t border-ink-850 px-4 py-3 first:border-t-0">
                    <span class="rounded border border-ink-700 px-1.5 py-px text-[10px] font-semibold tracking-wider text-ink-200 uppercase">entrevista</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-white">{{ $draft->company_name ?: $draft->user->name }}</p>
                        <p class="text-xs text-ink-400">parada no passo {{ $draft->step_reached }} desde {{ $draft->updated_at->timezone('America/Sao_Paulo')->format('d/m') }}</p>
                    </div>
                    <a href="{{ route('alerts.interview', ['passo' => $draft->step_reached, 'alerta' => $draft->id]) }}" class="text-xs text-accent hover:underline">Continuar entrevista</a>
                </div>
            @endforeach
        </section>

        <section class="{{ $box }} overflow-hidden">
            <div class="flex items-baseline justify-between border-b border-ink-800 px-4 py-3">
                <h2 class="text-sm font-semibold text-white">Tecnologias que mais geram alertas</h2>
                <span class="text-xs text-ink-400">30 dias</span>
            </div>
            @if ($topTechs === [])
                <p class="px-4 py-6 text-center text-sm text-ink-400">Nenhum alerta nos últimos 30 dias.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-[11px] tracking-widest text-ink-400 uppercase">
                        <tr><th class="px-4 py-2 font-medium">Tecnologia</th><th class="px-3 py-2 text-right font-medium">Perfis</th><th class="px-3 py-2 text-right font-medium">Alertas</th><th class="px-4 py-2 text-right font-medium">Não relevante</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($topTechs as $row)
                            <tr class="border-t border-ink-850">
                                <td class="px-4 py-2 text-white">
                                    @if ($row['tech_id'])
                                        <a href="{{ route('admin.alerts.catalog', ['editar' => $row['tech_id']]) }}" class="hover:text-accent">{{ $row['label'] }}</a>
                                    @else
                                        {{ $row['label'] }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">{{ $row['profiles'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['alerts'] }}</td>
                                <td class="px-4 py-2 text-right {{ $row['rate'] >= 20 ? 'text-orange-300' : '' }}">{{ $row['rate'] }}%{{ $row['rate'] >= 20 ? ' · revisar termos' : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <p class="border-t border-ink-850 px-4 py-2 text-xs text-ink-400">
                Fila: alerts {{ $queues['alerts'] ?? 0 }} · judge {{ $queues['judge'] ?? 0 }} · notifications {{ $queues['notifications'] ?? 0 }} · discovery {{ $queues['discovery'] ?? 0 }}
            </p>
        </section>
    </div>

    <form method="POST" action="{{ route('admin.alerts.config') }}" class="{{ $box }} max-w-4xl space-y-5 p-5">
        @csrf
        @method('PUT')
        <div>
            <h2 class="text-sm font-semibold text-white">Configurações dos alertas</h2>
            <p class="text-xs text-ink-400">Gravadas na tabela settings, com precedência sobre o .env, como o resto do admin.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['enabled', 'Alertas (chave geral)', 'desliga matching, envio e descoberta'],
                ['realtime_enabled', 'Alertas na hora', 'envio imediato após a análise'],
                ['digest_enabled', 'Resumo diário', 'resumos agendados por canal'],
                ['discovery_enabled', 'Descoberta por domínio', 'checagens passivas mensais'],
                ['ai_review_enabled', 'Parecer da IA antes do envio', 'o hermes lê a notícia, o perfil e as avaliações do cliente'],
            ] as [$name, $label, $desc])
                <label class="flex items-start gap-3">
                    <input type="hidden" name="{{ $name }}" value="0">
                    <input type="checkbox" name="{{ $name }}" value="1" @checked($config[$name]) class="mt-0.5 rounded border-ink-700 bg-ink-850 accent-accent">
                    <span><span class="block text-sm text-ink-200">{{ $label }}</span><span class="block text-xs text-ink-400">{{ $desc }}</span></span>
                </label>
            @endforeach
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['digest_default_time', 'Horário padrão do resumo', 'time', null],
                ['default_timezone', 'Fuso padrão', 'text', null],
                ['max_per_hour', 'Limite por canal, por hora', 'number', '1'],
                ['min_score', 'Score mínimo para alertar', 'number', '0'],
                ['ai_review_threshold', 'IA: envia acima de (%)', 'number', '0'],
                ['github_ai_threshold', 'GitHub: envia acima de (%)', 'number', '0'],
                ['discovery_every_days', 'Refazer descoberta a cada (dias, mínimo 30)', 'number', '30'],
                ['consent_version', 'Versão do termo de aceite', 'text', null],
            ] as [$name, $label, $type, $min])
                <label class="block">
                    <span class="mb-1 block text-xs tracking-widest text-ink-400 uppercase">{{ $label }}</span>
                    <input type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $config[$name]) }}" @if ($min !== null) min="{{ $min }}" @endif
                           class="{{ $input }} [color-scheme:dark]">
                    @error($name) <span class="mt-1 block text-xs text-red-400">{{ $message }}</span> @enderror
                </label>
            @endforeach
        </div>

        <p class="text-xs text-ink-400">
            Mudar a versão do termo vale para os próximos aceites. SLACK_CLIENT_ID e SLACK_CLIENT_SECRET ficam só no .env
            ({{ $health['oauth'] ? 'hoje: configurados' : 'hoje: ausentes, o botão “Adicionar ao Slack” fica desligado' }}).
            GITHUB_SEARCH_TOKEN também fica só no .env ({{ filled(config('secmon.github.token')) ? 'hoje: configurado' : 'hoje: ausente, o monitoramento do GitHub só busca repositórios, sem a busca em código' }}).
            Os prompts das duas etapas da IA do GitHub ficam em <a href="{{ route('admin.prompts.index', ['grupo' => 'github']) }}" class="text-accent hover:underline">Prompts</a>.
        </p>

        <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar configurações</button>
    </form>
@endsection
