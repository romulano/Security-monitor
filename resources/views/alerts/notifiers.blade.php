@extends('layouts.app')

@section('title', 'Notificadores · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $switch = 'relative h-6 w-11 shrink-0 rounded-full bg-ink-700 transition peer-checked:bg-accent '
        .'peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent '
        .'after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-ink-200 after:transition '
        .'peer-checked:after:translate-x-5 peer-checked:after:bg-ink-950';
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';

    $about = [
        'news' => 'Notícias que casam com as suas entrevistas, na hora e no resumo diário.',
        'radar' => 'Domínios parecidos com o seu, com print e parecer da IA, na hora e no resumo diário.',
        'github' => 'Vazamentos e repositórios suspeitos no GitHub, com as duas análises da IA, na hora e no resumo diário.',
        'tests' => 'O aviso de que ligamos os testes e a sua autorização está pronta: só o domínio e o link, nunca dados do teste.',
    ];
    // O que nenhum app recebe continua na aba Alertas; e o que falta para o tipo gerar alerta.
    $nowhere = [
        'news' => 'As notícias ficam só no Security Monitor, em Alertas.',
        'radar' => 'Os avisos do Radar ficam só no Security Monitor, em Alertas.',
        'github' => 'Os achados do GitHub ficam só no Security Monitor, em Alertas.',
        'tests' => 'Quando ligarmos os testes, você só fica sabendo pela área de testes.',
    ];
    $idle = [
        'news' => 'Nenhum alerta de notícias ativo ainda.',
        'radar' => 'O Radar ainda não vigia nenhum domínio seu.',
        'github' => 'O monitoramento do GitHub ainda não está ativo.',
        'tests' => 'Nenhuma autorização de teste ainda.',
    ];
    // Onde começa cada tipo que ainda não gera nada: a Integração; o aviso dos testes, a área deles.
    $start = ['tests' => [route('alerts.tests'), 'Abrir os testes']];
    // O aviso dos testes (só para a conta com os testes liberados) faz 4 tipos: a grade vira 2 por 2.
    $cols = count($types) === 4 ? 'sm:grid-cols-2' : 'sm:grid-cols-3';
    $cell = fn (int $i) => count($types) === 4
        ? [0 => '', 1 => 'border-t border-ink-800 sm:border-t-0 sm:border-l', 2 => 'border-t border-ink-800', 3 => 'border-t border-ink-800 sm:border-l'][$i]
        : ($i === 0 ? '' : 'border-t border-ink-800 sm:border-t-0 sm:border-l');
    // App ainda não conectado: o que ele oferece e onde conectar. O webhook continua aqui sempre (a conta pode ter vários).
    $offers = [
        'slack' => ['Alertas na hora e resumo diário num canal do seu workspace.', route('alerts.slack')],
        'discord' => ['Numa mensagem direta, só para você, ou num canal do servidor, para a equipe toda.', route('alerts.discord')],
        'telegram' => ['Numa conversa privada com o bot, só para você, ou num grupo, para a equipe toda.', route('alerts.telegram')],
        'webhook' => ['Direto no sistema da sua empresa: o Security Monitor chama a sua URL, num domínio comprovado, com o JSON assinado.', route('alerts.webhooks.create')],
    ];

    $pill = fn ($channel) => match (true) {
        $channel->status === 'pending' => [$channel->pendingLabel(), 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
        $channel->status !== 'active' => [$channel->isWebhook() ? 'envio parado' : 'precisa reconectar', 'border-red-900 bg-red-950/50 text-red-300', 'bg-red-400'],
        $channel->isPaused() => ['pausado', 'border-amber-800 bg-amber-950/40 text-amber-200', 'bg-amber-300'],
        default => ['conectado', 'border-emerald-800 bg-emerald-950/60 text-emerald-300', 'bg-emerald-400'],
    };
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div>
            <h1 class="text-xl font-semibold text-white">Notificadores</h1>
            <p class="text-sm text-ink-400">
                Por onde chegam os alertas e o resumo diário, e o que cada app recebe. Dá para usar mais de um.
                Pausar segura o envio sem desconectar; os alertas continuam guardados em <a href="{{ route('alerts.index') }}" class="text-accent hover:underline">Alertas</a>.
            </p>
        </div>

        @if ($channels->isNotEmpty())
            {{-- Quem recebe o quê agora: conectado, sem pausa e com o tipo ligado. --}}
            <section aria-label="Quem recebe cada tipo de alerta agora" class="grid gap-3 {{ $cols }}">
                @foreach ($types as $type => $label)
                    @php $to = $receivers[$type]; @endphp
                    <div class="rounded-xl border {{ $to->isEmpty() ? 'border-amber-800/70 bg-amber-950/20' : 'border-ink-800 bg-ink-900' }} px-4 py-3.5">
                        <p class="text-xs tracking-wide text-ink-400 uppercase">{{ $label }}</p>
                        @if ($to->isEmpty())
                            <p class="mt-1 font-semibold text-amber-200">Nenhum app recebe</p>
                            <p class="text-sm text-ink-400">{{ $nowhere[$type] }}</p>
                        @else
                            <p class="mt-1 font-semibold text-white">Vai para o {{ $to->map->appName()->join(', o ', ' e o ') }}</p>
                        @endif
                        @unless ($sources[$type])
                            <p class="mt-1 text-sm text-ink-400">{{ $idle[$type] }} <a href="{{ $start[$type][0] ?? route('alerts.integrations') }}" class="whitespace-nowrap text-accent hover:underline">{{ $start[$type][1] ?? 'Integrar' }}</a></p>
                        @endunless
                    </div>
                @endforeach
            </section>

            <form id="central" method="POST" action="{{ route('alerts.center.update') }}" class="space-y-3">
                @csrf
                @method('PUT')

                @foreach ($channels as $channel)
                    @php [$pillLabel, $pillClass, $dotClass] = $pill($channel); @endphp
                    <section id="canal-{{ $channel->id }}" class="rounded-xl border {{ $channel->isPaused() ? 'border-amber-800/70' : 'border-ink-800' }} bg-ink-900">
                        <div class="flex flex-wrap items-center justify-between gap-3 p-5">
                            <div class="flex min-w-0 flex-[1_1_280px] items-center gap-3.5">
                                <x-brand-icon :name="$channel->type" class="size-11" />
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 font-semibold text-white">
                                        {{ $channel->appName() }}
                                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-normal {{ $pillClass }}">
                                            <span class="size-1.5 rounded-full {{ $dotClass }}"></span>{{ $pillLabel }}
                                        </span>
                                    </p>
                                    <p class="truncate text-sm text-ink-200">Chegando em {{ $channel->label() }}</p>
                                    <p class="text-xs text-ink-400">
                                        Na hora: {{ $channel->realtime ? 'ligado' : 'desligado' }}
                                        · Resumo: {{ $channel->digest ? $channel->digest_time.', '.$channel->daysLabel() : 'desligado' }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button form="pausar-{{ $channel->id }}"
                                        class="{{ $button }} {{ $channel->isPaused() ? 'bg-accent font-semibold text-ink-950 hover:brightness-110' : 'border border-ink-700 text-ink-200 hover:border-ink-400' }}">
                                    {{ $channel->isPaused() ? 'Retomar envio' : 'Pausar envio' }}
                                </button>
                                <a href="{{ $channel->settingsUrl() }}" class="{{ $button }} border border-ink-800 text-ink-200 hover:border-ink-700">Preferências</a>
                            </div>
                        </div>

                        @if ($channel->isPaused())
                            <p class="border-t border-amber-900/60 bg-amber-950/20 px-5 py-3 text-sm text-amber-100">
                                @php $since = $channel->paused_at->timezone($channel->timezone ?: 'America/Sao_Paulo'); @endphp
                                <b class="text-white">Envio pausado desde {{ $since->format('d/m') }} às {{ $since->format('H:i') }}.</b>
                                Nada sai para o {{ $channel->appName() }}, nem o resumo diário, até você retomar. O que chegar nesse tempo fica guardado no Security Monitor e não é enviado depois.
                            </p>
                        @elseif ($channel->status === 'broken' && $channel->isWebhook())
                            <p class="border-t border-red-900/60 bg-red-950/30 px-5 py-3 text-sm text-red-200">
                                <b class="text-white">O envio para {{ $channel->label() }} parou.</b> {{ $channel->last_error }} As escolhas abaixo valem assim que um <a href="{{ $channel->settingsUrl() }}" class="underline">teste</a> der certo.
                            </p>
                        @elseif ($channel->status === 'broken')
                            <p class="border-t border-red-900/60 bg-red-950/30 px-5 py-3 text-sm text-red-200">
                                <b class="text-white">O {{ $channel->appName() }} recusou o último envio para {{ $channel->label() }}.</b> As escolhas abaixo valem assim que você <a href="{{ $channel->settingsUrl() }}" class="underline">reconectar</a>.
                            </p>
                        @elseif ($channel->status === 'pending' && $channel->isWebhook())
                            <p class="border-t border-amber-900/60 bg-amber-950/20 px-5 py-3 text-sm text-amber-100">
                                <b class="text-white">Falta comprovar o domínio de {{ $channel->webhook_host }}.</b> Até lá, o Security Monitor não chama o endereço. As escolhas abaixo valem assim que você <a href="{{ $channel->settingsUrl() }}" class="underline">comprovar</a>.
                            </p>
                        @elseif ($channel->status === 'pending')
                            <p class="border-t border-amber-900/60 bg-amber-950/20 px-5 py-3 text-sm text-amber-100">
                                <b class="text-white">O bot já está em {{ $channel->discord_guild_name ?: 'seu servidor' }}. Falta escolher o canal.</b> As escolhas abaixo valem assim que você <a href="{{ $channel->settingsUrl() }}" class="underline">escolher</a>.
                            </p>
                        @endif

                        <div class="grid border-t border-ink-800 {{ $cols }}">
                            @foreach ($types as $type => $label)
                                <label class="flex cursor-pointer items-start justify-between gap-4 p-5 {{ $cell($loop->index) }}">
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-white">{{ $label }}</span>
                                        <span class="mt-1 block text-sm text-ink-400">{{ $about[$type] }}</span>
                                    </span>
                                    <input type="hidden" name="tipos[{{ $channel->id }}][{{ $type }}]" value="0">
                                    <input type="checkbox" name="tipos[{{ $channel->id }}][{{ $type }}]" value="1" @checked(old('tipos.'.$channel->id.'.'.$type, $channel->wants($type)))
                                           class="peer sr-only" aria-label="{{ $label }} no {{ $channel->appName() }}">
                                    <span aria-hidden="true" class="{{ $switch }} mt-1"></span>
                                </label>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="min-w-0 flex-[1_1_300px] text-sm text-ink-400">Vale para o envio na hora, os segurados pelo horário silencioso, o reenvio e o resumo diário.</p>
                    <button class="{{ $button }} bg-accent px-5 font-semibold text-ink-950 hover:brightness-110">Salvar o que cada app recebe</button>
                </div>
            </form>

            {{-- Fora do formulário principal (formulário não pode ficar dentro de outro); os botões apontam para cá pelo atributo form. --}}
            @foreach ($channels as $channel)
                <form id="pausar-{{ $channel->id }}" method="POST" action="{{ route('alerts.center.pause', $channel) }}" hidden>
                    @csrf
                </form>
            @endforeach
        @endif

        @if ($missing->isNotEmpty())
            <section class="space-y-3" aria-label="{{ $channels->isEmpty() ? 'Escolha onde receber' : 'Conectar outro app' }}">
                @if ($channels->isNotEmpty())
                    <h2 class="pt-2 font-semibold text-white">Conectar outro app</h2>
                @endif
                <div class="grid gap-3 {{ [1 => '', 2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-3', 4 => 'sm:grid-cols-2'][$missing->count()] }}">
                    @foreach ($missing as $type)
                        @php $another = $type === 'webhook' && $webhooks > 0; @endphp
                        <a href="{{ $offers[$type][1] }}"
                           class="group flex {{ $missing->count() === 1 ? 'items-center' : 'flex-col' }} gap-4 rounded-xl border border-ink-800 bg-ink-900 p-5 transition hover:border-ink-700 hover:bg-ink-850">
                            <div class="flex items-center gap-3.5 {{ $missing->count() === 1 ? 'min-w-0 flex-1' : '' }}">
                                <x-brand-icon :name="$type" class="size-12" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-lg font-semibold text-white">{{ $another ? 'Outro webhook' : \App\Models\NotificationChannel::APPS[$type] }}</p>
                                    <span class="mt-0.5 inline-flex items-center gap-1.5 rounded-full border border-ink-700 bg-ink-850 px-2.5 py-0.5 text-xs whitespace-nowrap text-ink-400">
                                        <span class="size-1.5 rounded-full bg-ink-400"></span>{{ $another ? $webhooks.' '.($webhooks === 1 ? 'conectado' : 'conectados') : 'não conectado' }}
                                    </span>
                                </div>
                                @if ($missing->count() > 1)
                                    <svg class="size-5 shrink-0 text-ink-700 transition group-hover:translate-x-0.5 group-hover:text-ink-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                                @endif
                            </div>
                            <p class="text-sm text-ink-400 {{ $missing->count() === 1 ? 'hidden flex-[1_1_0] sm:block' : '' }}">{{ $offers[$type][0] }}</p>
                            @if ($missing->count() === 1)
                                <svg class="size-5 shrink-0 text-ink-700 transition group-hover:translate-x-0.5 group-hover:text-ink-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                            @endif
                        </a>
                    @endforeach
                </div>
                @if ($channels->isEmpty())
                    <p class="text-sm text-ink-400">Depois de conectar, é aqui que você escolhe o que cada app recebe (notícias, Radar de domínios, GitHub) e pausa o envio sem desconectar.</p>
                @endif
            </section>
        @endif
    </div>
@endsection
