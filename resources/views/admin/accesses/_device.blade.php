{{-- Ficha do IP: o mesmo aparelho (o código de DeviceSightings) em outros IPs, só para segurança. Nunca mostra conta:
     o código não é ligado a ninguém. Alto: código raro e o mesmo navegador; provável: raro com outro navegador, ou visto
     em até {{ config('secmon.monitoring.devices.common_ips') }} IPs; comum: visto em mais IPs que isso, e não liga ninguém. --}}
@php($when = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->timezone('America/Sao_Paulo')->format('d/m H:i') : '—')
<section class="mb-8">
    <h2 class="mb-1 text-xs font-semibold tracking-widest text-ink-700 uppercase">Mesmo aparelho em outros IPs</h2>
    <p class="mb-3 max-w-3xl text-xs text-ink-700">
        O código que o navegador calcula para quem navega sem login, visto também em outros IPs nos últimos
        {{ config('secmon.monitoring.devices.window_days') }} dias. Serve para seguir quem troca de IP numa tentativa de invasão.
    </p>
    @forelse ($devices as $code)
        <div class="mb-3 rounded-lg border border-ink-800 bg-ink-900 p-3 text-xs">
            <p class="text-ink-400">
                Aparelho <code class="font-mono text-ink-200">{{ substr($code->fingerprint, 0, 8) }}</code>
                @if ($code->level === 'common')
                    · <span class="text-ink-700">aparelho comum ({{ $code->ips }} IPs): não liga os IPs</span>
                @else
                    · visto em {{ $code->ips }} IP(s)
                @endif
            </p>
            @if ($code->level !== 'common' && $code->others->isNotEmpty())
                <ul class="mt-2 divide-y divide-ink-800">
                    @foreach ($code->others as $other)
                        <li class="flex flex-wrap items-baseline gap-x-2 py-1">
                            <a href="{{ route('admin.accesses.ip', ['ip' => $other->ip]) }}" class="font-mono break-all text-ink-200 hover:text-accent">{{ $other->ip }}</a>
                            <span class="text-ink-700">{{ $other->country ?? '—' }} · {{ $when($other->first_seen) }} a {{ $when($other->last_seen) }}</span>
                            <span @class(['rounded px-1 text-[10px]', 'bg-red-950/50 text-red-300' => $other->confidence === 'alto', 'bg-amber-950/60 text-amber-300' => $other->confidence !== 'alto'])>{{ $other->confidence }}</span>
                            <span class="block w-full truncate text-ink-700" title="{{ $other->user_agent }}">{{ $other->user_agent ?? '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="text-xs text-ink-400">Nenhum código de aparelho neste IP (só existe para quem navega sem login, com JavaScript e sem o sinal Global Privacy Control).</p>
    @endforelse
</section>
