@extends('layouts.app')

@section('title', 'Sobre · Security Monitor')
@section('og_title', 'About Security Monitor · Threat intel news merged into cases by AI')
@section('description', 'Security Monitor is an intelligence satellite: it picks up security news straight from the sources, decodes it with AI and transmits only what matters to you.')

@php
    $eyebrow = 'font-mono text-xs font-semibold tracking-[0.14em] text-accent uppercase';
    $h2 = 'mt-4 text-[26px] leading-tight font-bold tracking-tight text-white md:text-[32px]';
    $label = 'font-mono text-[10.5px] font-semibold tracking-[0.12em] text-ink-400 uppercase';

    $sweep = $lastSweep?->copy()->timezone('America/Sao_Paulo');

    // Unidades confidenciais: só a largura das tarjas muda de uma para outra.
    $classified = [
        ['02', [110], [150, 90], 'cl'],
        ['03', [80], [130, 120], 'wip'],
        ['04', [130], [100], 'cl'],
    ];

    $groups = [
        ['Laboratórios de pesquisa', 'Quem descobre e destrincha as campanhas.', ['Unit 42', 'Securelist', 'SentinelOne', 'Check Point', 'WeLiveSecurity', 'Proofpoint', 'Fortinet', 'F5 Labs', 'GreyNoise', 'CrowdStrike', 'Recorded Future', 'Wiz', 'Citizen Lab']],
        ['Imprensa especializada', 'Quem acompanha os incidentes no dia a dia.', ['BleepingComputer', 'The Hacker News', 'SecurityWeek', 'Dark Reading', 'The Register', 'CyberScoop', 'Infosecurity Magazine', 'WIRED']],
        ['Alertas oficiais e vazamentos', 'Avisos de governo, boletins de correção e bases de vazamentos.', ['NCSC (Reino Unido)', 'CERT-EU', 'CERT-FR', 'FBI IC3', 'Cisco PSIRT', 'Palo Alto PSIRT', 'Fortinet PSIRT', 'Have I Been Pwned']],
        ['Pesquisadores independentes', 'Análise e contexto de quem está há anos no assunto.', ['Krebs on Security', 'Schneier on Security', 'Troy Hunt', 'Graham Cluley', 'Kim Zetter', 'Objective-See']],
    ];

    $extracted = ['criticidade', 'CVEs', 'IOCs', 'famílias de malware', 'grupos atacantes', 'técnicas ATT&CK', 'setores afetados', 'países', 'produtos e fabricantes', 'vetor de acesso', 'dados expostos'];

    $steps = [
        ['Varredura', 'coleta dos feeds', 'De hora em hora, as antenas percorrem as fontes e captam tudo o que foi publicado desde a última passagem.'],
        ['Decodificação', 'extração do texto', 'O feed quase nunca traz a matéria inteira. O Security Monitor abre a página da notícia e extrai o texto completo.'],
        ['Triangulação', 'agrupamento em casos', 'Várias fontes falando do mesmo incidente viram um caso só, com a linha do tempo de cada atualização. Repetição sem novidade não entra no feed, e o que escapou é juntado duas vezes ao dia.'],
        ['Análise a bordo', 'resumo por IA', 'Uma IA lê o caso, escreve o resumo, classifica a criticidade e separa os indicadores em campos pesquisáveis.'],
        ['Transmissão', 'feed, busca e painel', 'O caso chega ao feed, à busca e ao painel, em português ou em inglês, pouco depois de sair na fonte.'],
        ['Canal direto', 'alertas no Slack, Discord, Telegram e webhook', 'Para quem ativa os alertas, o que afeta a sua stack segue direto para o Slack, o Discord ou o Telegram do time, ou para o sistema da empresa, por webhook.'],
    ];

    $uses = [
        ['Começar o dia sabendo o que é crítico', 'Um feed único, ordenado e classificado, em vez de trinta sites para abrir.'],
        ['Investigar em segundos', 'Busca com AND, OR e NOT e filtros por CVE, grupo atacante, setor ou indicador.'],
        ['Ser avisado só do que importa', 'Alertas sob medida para a stack da sua empresa, no canal em que o time já está.'],
    ];
@endphp

@section('page')
    {{-- Abertura --}}
    <div class="starfield relative overflow-hidden border-b border-ink-800">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_50%_70%_at_72%_50%,oklch(0.32_0.07_265/0.55),transparent_70%)]"></div>

        <div class="relative mx-auto grid max-w-6xl items-center gap-10 px-4 pt-14 pb-12 md:grid-cols-[1.05fr_1fr] md:pt-18 md:pb-16">
            <div>
                <p class="{{ $eyebrow }}">Registro de missão · TFN-01</p>
                <h1 class="mt-4 text-4xl leading-[1.08] font-bold tracking-tight text-white md:text-[46px]">
                    Em órbita, captando cada <span class="text-accent">sinal de ameaça</span>.
                </h1>
                <p class="mt-5 max-w-[540px] text-lg leading-relaxed">
                    O Security Monitor é um satélite de inteligência. Ele varre sem parar as frequências onde a segurança digital
                    é noticiada (laboratórios de pesquisa, alertas de governo, imprensa especializada) e leva para a sua
                    equipe só o que importa, já decodificado.
                </p>
                <div class="mt-7 flex flex-wrap gap-3">
                    <a href="{{ route('feed.index') }}" class="rounded-lg bg-accent px-4.5 py-2.5 text-sm font-semibold text-ink-950 hover:brightness-110">Ver a transmissão</a>
                    <a href="#como-funciona" class="rounded-lg border border-ink-700 px-4.5 py-2.5 text-sm font-semibold hover:border-ink-400">Como funciona</a>
                </div>
            </div>

            {{-- Órbitas em SVG: os dois pontos âmbar percorrem os anéis. Quem pede menos
                 movimento no sistema vê os pontos parados e o mascote estático. --}}
            <div class="relative mx-auto aspect-square w-full max-w-[470px]">
                <svg viewBox="-280 -280 560 560" aria-hidden="true"
                     class="absolute top-1/2 left-1/2 w-[119%] -translate-x-1/2 -translate-y-1/2 overflow-visible text-accent">
                    <defs>
                        <radialGradient id="orbit-glow">
                            <stop offset="0" stop-color="currentColor" stop-opacity="0.75"/>
                            <stop offset="1" stop-color="currentColor" stop-opacity="0"/>
                        </radialGradient>
                        <g id="orbit-dot">
                            <circle r="11" fill="url(#orbit-glow)">
                                <animate attributeName="opacity" values="0.5;1;0.5" dur="3s" repeatCount="indefinite"/>
                            </circle>
                            <circle r="3.5" fill="currentColor"/>
                        </g>
                        <g id="orbit-dot-sm">
                            <circle r="9" fill="url(#orbit-glow)">
                                <animate attributeName="opacity" values="1;0.5;1" dur="3s" repeatCount="indefinite"/>
                            </circle>
                            <circle r="2.8" fill="currentColor"/>
                        </g>
                    </defs>

                    <circle r="235" fill="none" stroke="currentColor" stroke-opacity="0.28" stroke-dasharray="3 5"/>
                    <circle r="180" fill="none" class="stroke-ink-400" stroke-opacity="0.16"/>
                    <ellipse rx="280" ry="100" transform="rotate(-18)" fill="none" stroke="currentColor" stroke-opacity="0.22" stroke-dasharray="3 5"/>

                    <g class="motion-reduce:hidden">
                        <use href="#orbit-dot">
                            <animateMotion dur="40s" begin="-35s" repeatCount="indefinite"
                                           path="M0,-235 A235,235 0 1,1 0,235 A235,235 0 1,1 0,-235"/>
                        </use>
                        <g transform="rotate(-18)">
                            <use href="#orbit-dot-sm">
                                <animateMotion dur="24s" begin="-4s" repeatCount="indefinite"
                                               path="M280,0 A280,100 0 1,1 -280,0 A280,100 0 1,1 280,0"/>
                            </use>
                        </g>
                    </g>
                    <g class="hidden motion-reduce:inline">
                        <use href="#orbit-dot" x="-166" y="-166"/>
                        <use href="#orbit-dot-sm" x="263" y="34" transform="rotate(-18)"/>
                    </g>
                </svg>

                <picture>
                    <source media="(prefers-reduced-motion: reduce)" srcset="/img/mascote.webp">
                    <img src="/img/mascote-animado.webp" width="462" height="480"
                         alt="Security Monitor, o satélite: um gigante mecânico com cabeças de serpente, antenas e asas de painel solar"
                         class="absolute top-1/2 left-1/2 w-[85%] -translate-x-1/2 -translate-y-1/2 drop-shadow-[0_20px_40px_rgb(0_0_0/0.6)]">
                </picture>

                <span class="absolute top-3 right-0 font-mono text-[10.5px] tracking-wider text-ink-400 uppercase">Alt 35.786 km · GEO</span>
                <span class="absolute bottom-2 left-1 font-mono text-[10.5px] tracking-wider text-ink-400 uppercase">
                    Sinal ● {{ $online ? 'estável' : 'fraco' }}
                </span>
            </div>
        </div>

        {{-- Telemetria: a única parte viva da página. --}}
        <div class="relative border-t border-ink-800 bg-ink-950/60">
            <dl class="mx-auto grid max-w-6xl grid-cols-2 gap-y-2 px-4 py-3 md:grid-cols-4 md:py-0">
                <div class="py-2 md:border-l md:border-ink-800 md:py-4.5 md:pl-5 md:first:border-l-0 md:first:pl-0">
                    <dt class="{{ $label }}">Fontes monitoradas</dt>
                    <dd class="mt-1.5 font-mono text-2xl font-semibold text-white">{{ $sources }}<small class="ml-1.5 text-xs font-normal text-ink-400">feeds RSS/Atom</small></dd>
                </div>
                <div class="py-2 md:border-l md:border-ink-800 md:py-4.5 md:pl-5">
                    <dt class="{{ $label }}">Casos analisados</dt>
                    <dd class="mt-1.5 font-mono text-2xl font-semibold text-white">{{ number_format($cases, 0, ',', '.') }}<small class="ml-1.5 text-xs font-normal text-ink-400">desde o lançamento</small></dd>
                </div>
                <div class="py-2 md:border-l md:border-ink-800 md:py-4.5 md:pl-5">
                    <dt class="{{ $label }}">Última varredura</dt>
                    <dd class="mt-1.5 font-mono text-2xl font-semibold text-white">
                        @if ($sweep)
                            {{ $sweep->format($sweep->isToday() ? 'H:i' : 'd/m') }}<small class="ml-1.5 text-xs font-normal text-ink-400">{{ $lastSweep->diffForHumans(short: true) }}</small>
                        @else
                            —<small class="ml-1.5 text-xs font-normal text-ink-400">ainda não houve</small>
                        @endif
                    </dd>
                </div>
                <div class="py-2 md:border-l md:border-ink-800 md:py-4.5 md:pl-5">
                    <dt class="{{ $label }}">Status</dt>
                    <dd class="mt-1.5 flex items-center gap-2 font-mono text-2xl font-semibold text-white">
                        @if ($online)
                            <span class="size-2 rounded-full bg-emerald-400 shadow-[0_0_10px_var(--color-emerald-400)]"></span>Em órbita
                        @else
                            <span class="size-2 rounded-full bg-accent shadow-[0_0_10px_var(--color-accent)]"></span>Sinal fraco
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- 01 · A missão --}}
    <section class="border-b border-ink-800 py-16 md:py-22">
        <div class="mx-auto max-w-6xl px-4">
            <div class="flex items-baseline gap-3.5"><span class="{{ $eyebrow }}">01 — A missão</span><span class="h-px flex-1 bg-ink-800"></span></div>

            <div class="mt-2 max-w-[760px]">
                <h2 class="{{ $h2 }}">Por que o Security Monitor foi lançado</h2>
                <div class="mt-4 space-y-4 text-[16.5px] leading-[1.75]">
                    <p>Toda manhã, quem defende uma empresa enfrenta o mesmo problema: centenas de notícias de segurança espalhadas por dezenas de sites, quase todas em inglês, repetindo o mesmo incidente com títulos diferentes. Separar o que é crítico do que é ruído toma horas, e o que importa costuma chegar tarde.</p>
                    <p>O Security Monitor nasceu para encurtar esse caminho. Em vez de mais um site para ler, um satélite que lê todos por você, junta as versões da mesma história e entrega um relatório só, em português ou em inglês.</p>
                    <p class="text-ink-400">O nome diz o serviço: um monitor de segurança que fica de guarda lá em cima, lendo tudo, para que a vigília não precise ser sua.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- 02 · A frota --}}
    <section class="border-b border-ink-800 py-16 md:py-22">
        <div class="mx-auto max-w-6xl px-4">
            <div class="flex items-baseline gap-3.5"><span class="{{ $eyebrow }}">02 — A frota</span><span class="h-px flex-1 bg-ink-800"></span></div>
            <h2 class="{{ $h2 }}">Parte da frota <span class="redact">######</span></h2>

            <div class="relative mt-8 overflow-hidden rounded-2xl border border-ink-800 bg-ink-900 bg-[image:repeating-linear-gradient(-45deg,transparent_0_14px,oklch(0.2_0.013_265/0.6)_14px_15px)] p-6 md:p-9">
                <div class="mb-6 inline-block -rotate-[9deg] rounded-md border-[3px] border-red-500/80 py-1.5 pr-3.5 pl-4.5 font-mono text-xl font-extrabold tracking-[0.22em] text-red-400/90 md:absolute md:top-7 md:right-8 md:mb-0">CLASSIFICADO</div>

                <p class="max-w-[700px] text-[16.5px] leading-[1.75]">
                    O Security Monitor não voa sozinho. Ele é a primeira unidade da frota <span class="redact">######</span>, um conjunto
                    de naves e serviços construídos para a mesma missão: transformar informação dispersa em decisão. O
                    restante da frota ainda é confidencial.
                </p>

                <div class="mt-7 grid gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="flex min-h-43 flex-col rounded-[10px] border border-accent/55 bg-ink-950 p-4.5 shadow-[inset_0_0_0_1px_oklch(0.78_0.16_85/0.15)]">
                        <p class="{{ $label }}">Unidade 01</p>
                        <p class="mt-3 text-[19px] font-bold text-white">Security Monitor</p>
                        <p class="mt-1.5 text-[13.5px] leading-normal text-ink-400">Satélite de inteligência. Capta, decodifica e transmite notícias de ameaças.</p>
                        <p class="mt-auto pt-3.5 font-mono text-[10.5px] font-semibold tracking-widest text-emerald-400">● EM ÓRBITA</p>
                    </div>
                    @foreach ($classified as [$n, $name, $fn, $status])
                        <div class="flex min-h-43 flex-col rounded-[10px] border border-ink-800 bg-ink-950 p-4.5">
                            <p class="{{ $label }}">Unidade {{ $n }}</p>
                            <p class="mt-3 text-[19px]">
                                @foreach ($name as $w)<span class="redact-bar" style="width: {{ $w }}px"></span>@endforeach
                            </p>
                            <p class="mt-1.5 flex flex-col gap-1.5 text-[13.5px]">
                                @foreach ($fn as $w)<span class="redact-bar" style="width: {{ $w }}px"></span>@endforeach
                            </p>
                            @if ($status === 'wip')
                                <p class="mt-auto pt-3.5 font-mono text-[10.5px] font-semibold tracking-widest text-accent">▲ EM CONSTRUÇÃO</p>
                            @else
                                <p class="mt-auto pt-3.5 font-mono text-[10.5px] font-semibold tracking-widest text-red-400/90">■ CLASSIFICADO</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <p class="mt-5.5 font-mono text-[11px] tracking-widest text-ink-700 uppercase">Acesso restrito · nível 4 · novas informações serão liberadas quando a missão permitir</p>
            </div>
        </div>
    </section>

    {{-- 03 · O que ele capta --}}
    <section class="border-b border-ink-800 py-16 md:py-22">
        <div class="mx-auto max-w-6xl px-4">
            <div class="flex items-baseline gap-3.5"><span class="{{ $eyebrow }}">03 — O que ele capta</span><span class="h-px flex-1 bg-ink-800"></span></div>
            <h2 class="{{ $h2 }}">Só sinais de segurança, direto na origem</h2>
            <p class="mt-4 max-w-[760px] text-[16.5px] leading-[1.75]">
                O Security Monitor escuta o feed RSS/Atom publicado pela própria fonte. Nada de agregadores no meio do caminho:
                cada notícia tem endereço de origem e pode ser conferida.
            </p>

            <div class="mt-8 grid gap-5 md:grid-cols-2">
                @foreach ($groups as [$title, $text, $names])
                    <div class="rounded-xl border border-ink-800 bg-ink-900 px-6 py-5.5">
                        <h3 class="text-[15.5px] font-semibold text-white">{{ $title }}</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-ink-400">{{ $text }}</p>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($names as $name)
                                <span class="rounded-md border border-ink-800 bg-ink-950 px-2 py-1 text-xs">{{ $name }}</span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 rounded-xl border border-dashed border-ink-700 px-6 py-5.5">
                <h3 class="{{ $label }}">De cada sinal, o Security Monitor extrai</h3>
                <div class="mt-3.5 flex flex-wrap gap-1.5">
                    @foreach ($extracted as $item)
                        <span class="rounded-md border border-accent/30 bg-ink-950 px-2 py-1 font-mono text-[11.5px] text-accent">{{ $item }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- 04 · Como funciona --}}
    <section id="como-funciona" class="scroll-mt-16 border-b border-ink-800 py-16 md:py-22">
        <div class="mx-auto max-w-6xl px-4">
            <div class="flex items-baseline gap-3.5"><span class="{{ $eyebrow }}">04 — Como funciona</span><span class="h-px flex-1 bg-ink-800"></span></div>
            <h2 class="{{ $h2 }}">O trajeto de um sinal</h2>

            <ol class="mt-9 grid gap-px overflow-hidden rounded-2xl border border-ink-800 bg-ink-800 md:grid-cols-3">
                @foreach ($steps as $i => [$title, $real, $text])
                    <li class="bg-ink-900 px-6.5 pt-6.5 pb-7">
                        <p class="font-mono text-xs font-semibold tracking-wider text-accent">{{ sprintf('%02d', $i + 1) }} <span class="text-ink-700">/ {{ sprintf('%02d', count($steps)) }}</span></p>
                        <h3 class="mt-2.5 text-lg font-bold text-white">{{ $title }}</h3>
                        <p class="mt-0.5 font-mono text-[11px] tracking-wide text-ink-400">{{ $real }}</p>
                        <p class="mt-2.5 text-[14.5px] leading-[1.65]">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- 05 · Para que serve --}}
    <section class="border-b border-ink-800 py-16 md:py-22">
        <div class="mx-auto max-w-6xl px-4">
            <div class="flex items-baseline gap-3.5"><span class="{{ $eyebrow }}">05 — Para que serve</span><span class="h-px flex-1 bg-ink-800"></span></div>
            <h2 class="{{ $h2 }}">Menos abas abertas, mais decisão</h2>

            <div class="mt-8 grid gap-8 md:grid-cols-3 md:gap-4.5">
                @foreach ($uses as [$title, $text])
                    <div class="border-t-2 border-accent pt-4.5">
                        <h3 class="text-lg leading-snug font-bold text-white">{{ $title }}</h3>
                        <p class="mt-2.5 text-[15px] leading-[1.65] text-ink-400">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Chamada final --}}
    <div class="starfield px-4 pt-20 pb-22 text-center">
        <p class="{{ $eyebrow }}">Fim da transmissão</p>
        <h2 class="mt-3.5 text-3xl font-bold tracking-tight text-white md:text-4xl">O Security Monitor está no ar agora.</h2>
        <p class="mx-auto mt-3.5 max-w-[560px] text-[16.5px] leading-[1.7] text-ink-400">
            Acompanhe o que ele captou nas últimas horas, ou configure os alertas da sua stack.
        </p>
        <div class="mt-7 flex flex-wrap justify-center gap-3">
            <a href="{{ route('feed.index') }}" class="rounded-lg bg-accent px-4.5 py-2.5 text-sm font-semibold text-ink-950 hover:brightness-110">Abrir o feed</a>
            <a href="{{ route('alerts.integrations') }}" class="rounded-lg border border-ink-700 px-4.5 py-2.5 text-sm font-semibold hover:border-ink-400">Configurar alertas</a>
        </div>
    </div>
@endsection
