{{--
    O pedaço da página da execução que muda sozinho: o cabeçalho com a pílula e as ações, a faixa do estado e,
    depois de começar, as 7 fases, o "Agora", o orçamento, a posse e a atividade. Espera $run e $live
    (PentestRunView::live()); opcional $confirmed (a confirmação recente vale para o domínio, e o Retomar não pede a
    senha). A consulta periódica troca este pedaço inteiro.
--}}
@php
    $tz = \App\Services\Pentest\PentestRunView::zone($run);
    $box = 'space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5';
    $title = 'text-[15px] font-semibold text-white';
    $banners = [
        'alerta' => ['border-amber-800 bg-amber-950/30', 'text-amber-100'],
        'parando' => ['border-red-900 bg-red-950/50', 'text-red-100'],
        'neutro' => ['border-ink-700 bg-ink-850', 'text-ink-200'],
        'erro' => ['border-red-900 bg-red-950/40', 'text-red-100'],
    ];
    $phaseBox = [
        'feita' => ['border-ink-800 bg-ink-900', 'border-emerald-800 bg-emerald-950 text-emerald-300', 'text-ink-200'],
        'agora' => ['border-accent/60 bg-accent/10', 'border-accent bg-accent text-ink-950', 'text-amber-200'],
        'pausada' => ['border-amber-800 bg-amber-950/30', 'border-amber-800 bg-amber-950 text-amber-200', 'text-amber-200'],
        'parada' => ['border-ink-700 bg-ink-850', 'border-ink-400 bg-ink-800 text-white', 'text-ink-200'],
        'depois' => ['border-dashed border-ink-700', 'border-ink-700 text-ink-400', 'text-ink-400'],
        'nao' => ['border-dashed border-ink-700', 'border-ink-700 text-ink-400', 'text-ink-400'],
    ];
@endphp

<div class="flex flex-wrap items-end justify-between gap-3">
    <div class="min-w-0 space-y-1">
        @if ($live['before'])
            <p class="text-xs text-ink-400">Antes de começar</p>
        @endif
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <h1 class="text-xl font-semibold break-words text-white"><span class="font-mono">{{ $run->domain }}</span></h1>
            @include('alerts.tests.partials.pill', ['pill' => $live['pill'], 'pulse' => in_array($live['situation'], ['running', 'stopping'], true)])
            <span class="font-mono text-xs text-ink-400">#{{ $run->shortId() }}</span>
        </div>
        <p class="text-sm text-ink-400">{{ $live['subtitle'] }}</p>
        {{-- Há quanto tempo atualizou (Execucao-ao-vivo): o JS reescreve a cada segundo, por isso fica fora do leitor de
             tela; ele ouve uma vez só, quando a consulta passa a falhar e quando volta (situação 9). --}}
        @if ($live['active'])
            <p data-run-age aria-hidden="true" class="text-xs text-ink-400">Atualiza sozinho a cada {{ (int) config('secmon.pentest.live_poll_seconds') }} s.</p>
        @endif
    </div>

    <div class="flex w-full flex-wrap items-end gap-2 sm:w-auto">
        @if ($live['actions']['pause'])
            <form method="POST" action="{{ route('alerts.tests.runs.pause', ['execucao' => $run->uuid]) }}">
                @csrf
                <button class="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400 hover:text-white">
                    <svg aria-hidden="true" class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5v14"/><path d="M15 5v14"/></svg>
                    Pausar
                </button>
            </form>
        @endif
        @if ($live['actions']['resume'] || $live['actions']['resumeNow'])
            {{-- Retomar volta a mandar tráfego: pede a confirmação recente, como "Nova execução" (I1 do Red Team). --}}
            <form method="POST" action="{{ route('alerts.tests.runs.resume', ['execucao' => $run->uuid]) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                @unless ($confirmed ?? false)
                    <label class="min-w-44 flex-1">
                        <span class="mb-1 block text-xs text-ink-400">Senha da sua conta, para retomar</span>
                        <input type="password" name="reauth_password" autocomplete="current-password"
                               class="min-h-11 w-full rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent">
                    </label>
                @endunless
                <button class="inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">
                    <svg aria-hidden="true" class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 5l12 7-12 7z"/></svg>
                    {{ $live['actions']['resume'] ? 'Retomar' : 'Retomar agora' }}
                </button>
                @error('reauth_password', 'reauth')<p class="w-full text-sm text-red-300">{{ $message }}</p>@enderror
            </form>
        @endif
        @if ($live['actions']['stop'])
            @include('alerts.tests.partials.stop', ['form' => 'max-sm:flex-1', 'class' => 'w-full'])
        @endif
        @if ($live['actions']['cancel'])
            @php $request = $run->status === 'awaiting_approval'; @endphp
            <form method="POST" action="{{ route('alerts.tests.runs.stop', ['execucao' => $run->uuid]) }}" data-confirm-ok="{{ $request ? 'Cancelar o pedido' : 'Cancelar o teste' }}" data-confirm-tone="danger"
                  data-confirm-cancel="{{ $request ? 'Manter o pedido' : 'Manter o teste' }}"
                  data-confirm="{{ $request ? 'Cancelar o pedido de teste em' : 'Cancelar o teste em' }} {{ $run->domain }}? Nada foi enviado ao site, e a execução não começa.">
                @csrf
                <button class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-red-400 hover:text-red-200">{{ $request ? 'Cancelar o pedido' : 'Cancelar' }}</button>
            </form>
        @endif
        @if ($run->pentestDomain && $live['situation'] === 'scheduled')
            <a href="{{ route('alerts.tests.authorize', ['passo' => 4, 'dominio' => $run->pentestDomain->host]) }}"
               class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400 hover:text-white">Mudar a janela</a>
        @endif
        @if ($run->pentestDomain && ($live['before'] || ! $live['active']))
            <a href="{{ route('alerts.tests.domains.show', ['dominio' => $run->pentestDomain->host]) }}"
               class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400 hover:text-white">Ver a autorização</a>
        @endif
    </div>
</div>

@if ($banner = $live['banner'])
    @php [$bannerBox, $bannerText] = $banners[$banner['tone']] ?? $banners['neutro']; @endphp
    <section @if ($banner['role'] === 'alert') role="alert" @else role="status" @endif class="space-y-2 rounded-xl border px-4 py-3.5 text-sm {{ $bannerBox }}">
        <p class="font-semibold text-white">{{ \App\Services\Pentest\PentestRunView::withDomain($banner['title'], $run->domain) }}</p>
        @if ($banner['text'] !== '')
            <p class="{{ $bannerText }}">{{ \App\Services\Pentest\PentestRunView::withDomain($banner['text'], $run->domain) }}</p>
        @endif
        @if (! empty($banner['note']))
            <div class="rounded-lg border border-ink-800 bg-ink-950/40 px-3 py-2">
                <p class="text-xs text-ink-400">Motivo escrito pelo analista</p>
                <p class="text-ink-200">{{ $banner['note'] }}</p>
            </div>
        @endif
        @if (! empty($banner['checks']))
            <p class="flex flex-wrap items-center gap-2 text-xs text-ink-400">
                Antes de começar conferimos de novo:
                @foreach (['TXT no ar', 'termo aceito', 'janela', 'testes ligados no Security Monitor'] as $check)
                    <span class="rounded-full border border-ink-700 px-2 py-0.5 text-ink-200">{{ $check }}</span>
                @endforeach
            </p>
        @endif
        @if (! empty($banner['txt']) && $run->pentestDomain)
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-red-200">Tipo</dt><dd class="font-mono text-white">TXT</dd>
                <dt class="text-red-200">Nome</dt><dd class="font-mono text-white">@</dd>
                <dt class="text-red-200">Valor</dt><dd class="font-mono break-all text-white">{{ $run->pentestDomain->domain?->txtValue() }}</dd>
            </dl>
            <a href="{{ route('alerts.tests.domains.show', ['dominio' => $run->pentestDomain->host]) }}" class="inline-flex min-h-11 items-center font-semibold text-red-200 underline">Conferir o TXT</a>
        @endif
        @if (! empty($banner['renew']) && $run->pentestDomain)
            <a href="{{ route('alerts.tests.authorize', ['passo' => 4, 'dominio' => $run->pentestDomain->host]) }}" class="inline-flex min-h-11 items-center font-semibold text-accent hover:underline">Escolher nova janela</a>
        @endif
        @if ($live['situation'] === 'rejected')
            <p class="flex flex-wrap gap-x-4 gap-y-1">
                <a href="mailto:{{ config('secmon.contact_email') }}" class="inline-flex min-h-11 items-center font-semibold text-accent hover:underline">Falar com o Security Monitor</a>
                @if ($run->pentestDomain)
                    <a href="{{ route('alerts.tests.domains.show', ['dominio' => $run->pentestDomain->host]) }}#nova-execucao" class="inline-flex min-h-11 items-center font-semibold text-accent hover:underline">Ajustar e pedir de novo</a>
                @endif
            </p>
        @endif
    </section>
@endif

@unless ($live['before'])
    <section aria-labelledby="fases" class="space-y-3">
        <h2 id="fases" class="{{ $title }}">As 7 fases</h2>
        <ol class="grid grid-cols-[repeat(auto-fit,minmax(min(130px,100%),1fr))] gap-2">
            @foreach ($live['phases'] as $phase)
                @php [$cell, $circle, $info] = $phaseBox[$phase['state']]; @endphp
                <li @if (in_array($phase['state'], ['agora', 'pausada', 'parada'], true)) aria-current="step" @endif class="flex min-h-[72px] flex-col gap-1.5 rounded-lg border px-3 py-2.5 {{ $cell }}">
                    <span class="flex items-center gap-2">
                        <span aria-hidden="true" class="grid size-[22px] shrink-0 place-items-center rounded-full border text-[11px] font-semibold {{ $circle }} {{ $phase['state'] === 'agora' ? 'motion-safe:animate-pulse' : '' }}">{{ $phase['state'] === 'feita' ? '✓' : $phase['number'] }}</span>
                        <span class="text-sm font-semibold text-white">{{ $phase['name'] }}</span>
                    </span>
                    <span class="text-xs {{ $info }}"><span class="sr-only">Fase {{ $phase['number'] }} de 7: </span>{{ $phase['info'] }}</span>
                </li>
            @endforeach
        </ol>
        @if ($live['recon'])
            <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-400">
                @foreach ($live['recon'] as $item)
                    <span><span class="text-ink-200">{{ $item['name'] }}</span> · {{ $item['state'] }}</span>
                @endforeach
            </p>
        @endif
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- O cartão que diz o que acontece no site agora, em destaque só enquanto o teste vive (canvas: Execucao-ao-vivo). --}}
        <section aria-labelledby="agora" class="space-y-2 rounded-xl border {{ in_array($run->status, ['running', 'paused', 'stopping'], true) ? 'border-accent/40' : 'border-ink-800' }} bg-ink-900 p-5">
            <h2 id="agora" class="text-[11px] tracking-[0.1em] text-ink-400 uppercase">Agora</h2>
            <p class="{{ $title }}">{{ $live['now']['title'] }}</p>
            <p class="text-sm text-ink-200">{{ $live['now']['text'] }}</p>
            <p class="text-xs text-ink-400">Progresso: {{ $live['now']['progress'] }}</p>
        </section>

        <section aria-labelledby="orcamento" class="{{ $box }}">
            <h2 id="orcamento" class="text-[11px] tracking-[0.1em] text-ink-400 uppercase">Orçamento</h2>
            <p class="text-sm"><span class="text-2xl font-semibold text-white">{{ $live['budget']['used'] }}</span> <span class="text-ink-400">de {{ $live['budget']['max'] }} requisições ({{ $live['budget']['pct'] }}%)</span></p>
            <div class="h-1.5 overflow-hidden rounded-full bg-ink-800" role="progressbar" aria-label="Requisições usadas" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $live['budget']['pct'] }}">
                <div class="h-full rounded-full bg-accent" style="width: {{ $live['budget']['pct'] }}%"></div>
            </div>
            <p class="text-sm text-ink-200">{{ $live['budget']['activity'] }}</p>
            <p class="text-xs text-ink-400">{{ $live['budget']['rhythm'] }}</p>
        </section>

        {{-- Nas duas colunas até a "Saúde do site" (P10) ocupar o lado dela. --}}
        <section aria-labelledby="posse" class="{{ $box }} lg:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="posse" class="text-[11px] tracking-[0.1em] text-ink-400 uppercase">Posse</h2>
                @include('alerts.tests.partials.pill', ['pill' => [$live['ownership']['label'], $live['ownership']['tone']]])
            </div>
            <p class="text-sm text-ink-200">{{ $live['ownership']['text'] }}</p>
            <p class="text-xs text-ink-400">Conferimos no início, em cada fase e a cada 15 minutos. Se o TXT sumir, o teste para.</p>
        </section>

        {{-- Na largura toda, embaixo da grade (canvas); no celular, o rótulo vai na linha do texto. --}}
        <section aria-labelledby="atividade" class="{{ $box }} lg:col-span-2">
            <h2 id="atividade" class="text-[11px] tracking-[0.1em] text-ink-400 uppercase">Atividade recente</h2>
            @if ($live['activity'] === [])
                <p class="text-sm text-ink-400">Nada ainda.</p>
            @else
                <ol class="divide-y divide-ink-800 text-sm">
                    @foreach ($live['activity'] as $entry)
                        <li class="flex gap-3 py-1.5">
                            <span class="w-12 shrink-0 font-mono text-xs leading-5 text-ink-400">{{ $entry['at'] }}</span>
                            <span class="hidden w-24 shrink-0 text-xs leading-5 text-ink-400 sm:inline">{{ $entry['label'] }}</span>
                            <span class="min-w-0 flex-1 text-ink-200"><span class="text-xs text-ink-400 sm:hidden">{{ $entry['label'] }} · </span>{{ $entry['text'] }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
@endunless
