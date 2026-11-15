@extends('layouts.admin')

@section('title', $site->host.' · Segurança')

@section('content')
    @php
        $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—';
        $button = 'rounded-md border border-ink-800 px-3 py-1.5 text-xs text-ink-400 hover:text-ink-200';
        $failed = $last && $last->status === 'erro';
    @endphp

    <div class="mb-1 text-xs"><a href="{{ route('admin.security') }}" class="text-ink-400 hover:text-ink-200">← Segurança dos sites</a></div>

    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="flex flex-wrap items-center gap-2 text-lg font-semibold text-white">
                {{ $site->host }}
                @include('admin.security._state', ['status' => $site->last_status])
                @unless ($site->enabled)<span class="text-xs font-normal text-ink-700">pausado</span>@endunless
            </h1>
            <p class="mt-1 text-xs text-ink-700">
                Última verificação: {{ $when($site->last_check_at) }}
                @if ($site->enabled) · próxima: {{ $when($site->next_check_at) }} @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($site->enabled)
                <form method="POST" action="{{ route('admin.security.run', $site) }}">
                    @csrf
                    <button class="rounded-md bg-accent px-3 py-1.5 text-xs font-semibold text-ink-950 hover:brightness-110">Verificar agora</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.security.toggle', $site) }}">
                @csrf
                <button class="{{ $button }}">{{ $site->enabled ? 'Pausar' : 'Retomar' }}</button>
            </form>
            <form method="POST" action="{{ route('admin.security.destroy', $site) }}"
                  data-confirm="Remover {{ $site->host }} da monitoria? O histórico de verificações dele também é apagado.">
                @csrf
                @method('DELETE')
                <button class="{{ $button }} hover:text-red-400">Remover</button>
            </form>
        </div>
    </div>

    @if ($site->isStale($staleHours))
        <div class="mb-5 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-3 text-sm text-red-300">
            Este site está sem verificação há mais de {{ $staleHours }} h. O agendador ou a fila <code class="font-mono">security</code> pode ter parado.
        </div>
    @endif

    @unless ($site->ownership)
        <section class="mb-5 space-y-3">
            <div>
                <h2 class="text-sm font-semibold text-amber-200">Comprove que o domínio é seu</h2>
                <p class="mt-1 max-w-3xl text-xs leading-relaxed text-ink-400">
                    Sem a prova, só rodam as verificações passivas (certificado, DNS e cabeçalhos da página inicial). Os arquivos expostos
                    (<code class="font-mono">/.env</code>, <code class="font-mono">/.git</code>…) são requisições a caminhos do site e só rodam depois da prova.
                </p>
            </div>
            <x-domain-ownership :state="$ownership" :action="route('admin.security.ownership', $site)" button="Conferir agora" class="max-w-3xl">
                O monitor confere o registro a cada varredura.
            </x-domain-ownership>
        </section>
    @endunless

    @if (! $last)
        <p class="rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm text-ink-400">Ainda não houve verificação. A primeira sai nos próximos minutos (o agendador olha a cada 10).</p>
    @elseif ($failed)
        <p class="rounded-lg border border-red-800/60 bg-red-950/40 p-4 text-sm text-red-300">
            A última verificação ({{ $when($last->started_at) }}) não conseguiu falar com o site: {{ $last->error }}.
        </p>
    @else
        <section class="mb-6 grid gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <p class="mb-1 text-[11px] tracking-widest text-ink-700 uppercase">Nota</p>
                <p class="font-mono text-2xl text-white">{{ $last->score }}<span class="text-sm text-ink-700">/100</span></p>
            </div>
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <p class="mb-1 text-[11px] tracking-widest text-ink-700 uppercase">Problemas abertos</p>
                <p class="font-mono text-2xl {{ $findings->where('severity', 'critico')->isNotEmpty() ? 'text-red-300' : 'text-white' }}">{{ $findings->count() }}</p>
            </div>
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <p class="mb-1 text-[11px] tracking-widest text-ink-700 uppercase">Certificado</p>
                <p class="text-sm text-ink-200">{{ $last->tls_protocol ?? '—' }}</p>
                <p class="text-xs text-ink-700">vence {{ $last->tls_not_after?->timezone('America/Sao_Paulo')->format('d/m/Y') ?? '—' }}</p>
            </div>
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <p class="mb-1 text-[11px] tracking-widest text-ink-700 uppercase">Verificação</p>
                <p class="text-sm text-ink-200">{{ number_format($last->duration_ms / 1000, 1, ',', '.') }} s</p>
                <p class="text-xs text-ink-700">HTTP {{ $last->http_status ?? '—' }}</p>
            </div>
        </section>

        @if ($first && $findings->isNotEmpty())
            <p class="mb-6 rounded-lg border border-ink-800 bg-ink-900 px-4 py-3 text-sm text-ink-400">
                Primeira verificação deste site: os problemas encontrados estão abaixo. A partir da próxima, aqui aparece só o que piorar.
            </p>
        @endif

        @if ($worsened->isNotEmpty())
            <section class="mb-6 rounded-lg border border-red-800/60 bg-red-950/30 p-4">
                <h2 class="text-sm font-semibold text-red-200">O que piorou desde a verificação anterior</h2>
                <ul class="mt-2 space-y-1 text-sm text-ink-200">
                    @foreach ($worsened as $event)
                        <li>
                            <span class="font-medium">{{ App\Services\Security\CheckCatalog::label($event->code) }}</span>
                            <span class="text-ink-700">· {{ App\Models\SecurityEvent::KINDS[$event->kind] ?? $event->kind }}</span>
                            @if ($event->detail) <span class="text-ink-400">· {{ $event->detail }}</span> @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="mb-8 space-y-5">
            @foreach ($groups as $group)
                <section>
                    <h2 class="mb-2 text-[11px] tracking-widest text-ink-700 uppercase">{{ $group['label'] }}</h2>
                    <div class="divide-y divide-ink-800 overflow-hidden rounded-lg border border-ink-800 bg-ink-900">
                        @foreach ($group['rows'] as $row)
                            <div class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm text-ink-200">
                                        {{ $row['label'] }}
                                        @if ($row['subject'] !== '') <span class="font-mono text-xs text-ink-400">{{ $row['subject'] }}</span> @endif
                                    </p>
                                    @include('admin.security._state', ['status' => $row['status']])
                                </div>
                                @if ($row['value'] !== '')
                                    <p class="mt-0.5 text-xs text-ink-400">{{ $row['value'] }}</p>
                                @endif
                                @if ($row['advice'])
                                    <p class="mt-1 text-xs leading-relaxed text-ink-700">{{ $row['advice'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section>
            <h2 class="mb-2 text-[11px] tracking-widest text-ink-700 uppercase">Mudanças</h2>
            <div class="divide-y divide-ink-800 overflow-hidden rounded-lg border border-ink-800 bg-ink-900 text-sm">
                @forelse ($events as $event)
                    <div class="flex items-start justify-between gap-3 px-4 py-2.5">
                        <p class="min-w-0 text-ink-200">
                            {{ App\Services\Security\CheckCatalog::label($event->code) }}
                            <span class="text-ink-700">· {{ App\Models\SecurityEvent::KINDS[$event->kind] ?? $event->kind }}</span>
                            @if ($event->detail) <span class="block truncate text-xs text-ink-400">{{ $event->detail }}</span> @endif
                        </p>
                        <span class="shrink-0 font-mono text-[11px] text-ink-700">{{ $event->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</span>
                    </div>
                @empty
                    <p class="px-4 py-3 text-ink-400">Nada mudou ainda.</p>
                @endforelse
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-[11px] tracking-widest text-ink-700 uppercase">Histórico de verificações</h2>
            <div class="divide-y divide-ink-800 overflow-hidden rounded-lg border border-ink-800 bg-ink-900 text-sm">
                @forelse ($history as $check)
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <span class="font-mono text-xs text-ink-400">{{ $check->started_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</span>
                        <span class="font-mono text-ink-200">{{ $check->status === 'erro' ? '—' : $check->score.'/100' }}</span>
                        @include('admin.security._state', ['status' => $check->status])
                    </div>
                @empty
                    <p class="px-4 py-3 text-ink-400">Sem verificações.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
