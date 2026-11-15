@extends('layouts.app')

@section('title', 'Kit de denúncia · '.$lookalike->displayHost().' · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Models\LookalikeCapture;
    use Illuminate\Support\Carbon;

    $capture = $lookalike->lastCapture?->status === 'done' ? $lookalike->lastCapture : null;
    $reports = $lookalike->reports ?? [];
    $sentAt = fn (string $key) => ! empty($reports[$key]['at']) ? Carbon::parse($reports[$key]['at'])->timezone('America/Sao_Paulo')->format('d/m \à\s H:i') : null;
    $photo = $lookalike->latestAlert?->photo;
@endphp

@section('content')
    <div class="mx-auto max-w-5xl space-y-6">
        <a href="{{ route('alerts.lookalikes.show', $lookalike) }}" class="inline-flex items-center gap-1 text-sm text-ink-400 hover:text-white">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            {{ $lookalike->displayHost() }}
        </a>

        <header class="space-y-2">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-2xl font-semibold text-white">Kit de denúncia</h1>
                @include('alerts.lookalikes.partials.risk', ['risk' => $lookalike->risk])
                @if ($lookalike->takenDown())
                    <span class="rounded border border-emerald-800/70 bg-emerald-950/50 px-1.5 py-px text-xs text-emerald-300">Fora do ar após denúncia</span>
                @endif
            </div>
            <p class="max-w-3xl text-sm text-ink-400">
                Para pedir a derrubada de <span class="font-mono text-ink-200">{{ $lookalike->displayHost() }}</span>. O Security Monitor não envia nada em seu nome:
                a denúncia sai do seu e-mail, como titular da marca. Marque cada caminho ao enviar; o Radar passa a abrir o domínio todo dia e mostra quando ele cair.
            </p>
        </header>

        {{-- 1. Provas --}}
        <section class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
            <div class="flex items-baseline gap-3">
                <span class="font-mono text-sm text-accent">1</span>
                <h2 class="font-semibold text-white">Junte as provas</h2>
            </div>
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                <div class="space-y-3">
                    @if ($capture && ($device = $photo['device'] ?? ($capture->devicesWithShots()[0] ?? null)) && $capture->hasShot($device))
                        @php $moment = $photo['moment'] ?? $capture->latestMoment($device); @endphp
                        <img src="{{ route('alerts.lookalikes.shot', [$capture, $device, $moment === 'second' ? 'depois' : 'first']) }}" alt="Print de {{ $lookalike->displayHost() }}" class="w-full rounded-lg border border-ink-800 bg-white">
                        <p class="text-xs text-ink-400">{{ LookalikeCapture::DEVICE_HINTS[$device] }} · {{ $capture->captured_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $capture->final_url }}</p>
                    @else
                        <p class="rounded-lg border border-dashed border-ink-700 bg-ink-950 p-6 text-center text-sm text-ink-400">Sem print ainda: {{ $lookalike->stateLabel() }}.</p>
                    @endif
                    @if ($capture && $capture->devicesWithShots() !== [])
                        <div>
                            <p class="text-[11px] tracking-widest text-ink-400 uppercase">Prints para anexar</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($capture->devicesWithShots() as $device)
                                    @foreach (['first', 'second'] as $moment)
                                        @if ($capture->hasShot($device, $moment) && ($moment === 'first' || $capture->changed($device)))
                                            <a href="{{ route('alerts.lookalikes.shot', [$capture, $device, $moment === 'second' ? 'depois' : 'first', 'baixar' => 1]) }}"
                                               class="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-ink-400">
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/></svg>
                                                {{ LookalikeCapture::DEVICES[$device] }}{{ $capture->changed($device) ? ' · '.mb_strtolower(LookalikeCapture::MOMENTS[$moment]) : '' }}
                                            </a>
                                        @endif
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                <dl class="grid content-start grid-cols-[110px_1fr] gap-x-3 gap-y-2 text-sm">
                    <dt class="text-ink-400">Endereço</dt>
                    <dd class="font-mono text-xs break-all text-ink-200">{{ $kit['url'] }}</dd>
                    <dt class="text-ink-400">IP</dt>
                    <dd class="font-mono text-xs break-all text-ink-200">{{ implode(', ', $lookalike->ips ?? []) ?: '—' }}</dd>
                    <dt class="text-ink-400">Registrado</dt>
                    <dd class="text-ink-200">{{ $lookalike->registered_at ? $lookalike->registered_at->format('d/m/Y').' ('.$lookalike->registeredAgo().')' : 'não informado' }}</dd>
                    <dt class="text-ink-400">Registrador</dt>
                    <dd class="text-ink-200">{{ $lookalike->rdap['registrar'] ?? 'não informado' }}</dd>
                    <dt class="text-ink-400">Hospedagem</dt>
                    <dd class="text-ink-200">{{ $lookalike->hosting['org'] ?? $lookalike->hosting['network'] ?? 'não identificada' }}</dd>
                    <dt class="text-ink-400">E-mail (MX)</dt>
                    <dd class="font-mono text-xs break-all {{ $lookalike->hasMx() ? 'text-amber-200' : 'text-ink-200' }}">{{ implode(', ', $lookalike->mx ?? []) ?: 'não recebe' }}</dd>
                    @if ($lookalike->ai_reason)
                        <dt class="text-ink-400">Parecer da IA</dt>
                        <dd class="text-ink-200">{{ $lookalike->ai_score }}% · {{ $lookalike->ai_reason }}</dd>
                    @endif
                </dl>
            </div>
        </section>

        {{-- 2. Quem pode derrubar --}}
        <section class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
            <div class="flex items-baseline gap-3">
                <span class="font-mono text-sm text-accent">2</span>
                <h2 class="font-semibold text-white">Escreva para quem pode derrubar</h2>
            </div>
            <ul class="divide-y divide-ink-850">
                @foreach ($kit['contacts'] as $contact)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-sm text-ink-200">{{ $contact['label'] }}</p>
                            <p class="font-mono text-sm break-all text-accent select-all">{{ $contact['email'] }}</p>
                            <p class="text-xs text-ink-400">{{ $contact['why'] }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="mailto:{{ $contact['email'] }}?subject={{ rawurlencode('Denúncia de phishing: '.$lookalike->host) }}&body={{ rawurlencode($kit['text_pt']) }}"
                               class="inline-flex min-h-9 items-center rounded-md border border-ink-700 px-3 text-sm text-ink-200 hover:border-ink-400">Abrir no e-mail</a>
                            @include('alerts.lookalikes.partials.report-toggle', ['key' => $contact['key'], 'at' => $sentAt($contact['key'])])
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach (['pt' => 'Texto em português', 'en' => 'Texto em inglês (registradores de fora do Brasil)'] as $lang => $label)
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <label for="denuncia-{{ $lang }}" class="text-[11px] tracking-widest text-ink-400 uppercase">{{ $label }}</label>
                            <button type="button" data-copy="#denuncia-{{ $lang }}" class="min-h-8 rounded-md border border-ink-700 px-2.5 text-xs text-ink-200 hover:border-ink-400">Copiar</button>
                        </div>
                        <textarea id="denuncia-{{ $lang }}" rows="12" readonly
                                  class="mt-1.5 w-full rounded-lg border border-ink-800 bg-ink-950 p-3 font-mono text-xs leading-5 text-ink-200">{{ $kit['text_'.$lang] }}</textarea>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- 3. Bloqueadores --}}
        <section class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5">
            <div class="flex items-baseline gap-3">
                <span class="font-mono text-sm text-accent">3</span>
                <h2 class="font-semibold text-white">Reporte também nos bloqueadores</h2>
            </div>
            <p class="text-sm text-ink-400">Enquanto o domínio não cai, eles fazem o navegador e o e-mail avisarem quem tentar abrir.</p>
            <ul class="divide-y divide-ink-850">
                @foreach ($kit['links'] as $link)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm text-accent hover:underline">{{ $link['label'] }} ↗</a>
                            <p class="text-xs text-ink-400">{{ $link['why'] }}</p>
                        </div>
                        @include('alerts.lookalikes.partials.report-toggle', ['key' => $link['key'], 'at' => $sentAt($link['key'])])
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
@endsection
