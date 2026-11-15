{{--
    Prints de uma visita em cada aparelho. Tela que mudou nos 10 s mostra os dois
    momentos; igual, mostra um só. Recebe $capture e, opcional, $compact (histórico).
--}}
@php
    use App\Models\LookalikeCapture;
    $devices = $capture->devicesWithShots();
    $mobile = array_values(array_intersect($devices, ['android', 'iphone']));
@endphp

@if (in_array('desktop', $devices, true))
    <figure class="space-y-1.5">
        <figcaption class="flex flex-wrap items-baseline gap-x-2 text-xs">
            <span class="font-semibold text-ink-200">Navegador</span>
            <span class="text-ink-400">{{ LookalikeCapture::DEVICE_HINTS['desktop'] }} · {{ $capture->changed('desktop') ? 'a tela mudou depois de 10 s' : 'igual ao acessar e depois de 10 s' }}</span>
        </figcaption>
        <div class="grid gap-2 {{ $capture->changed('desktop') ? 'sm:grid-cols-2' : '' }}">
            @foreach (['first', 'second'] as $moment)
                @if ($capture->hasShot('desktop', $moment) && ($moment === 'first' || $capture->changed('desktop')))
                    <a href="{{ route('alerts.lookalikes.shot', [$capture, 'desktop', $moment === 'second' ? 'depois' : 'first']) }}" target="_blank" rel="noopener" class="group relative block">
                        <img src="{{ route('alerts.lookalikes.shot', [$capture, 'desktop', $moment === 'second' ? 'depois' : 'first']) }}" alt="Navegador · {{ LookalikeCapture::MOMENTS[$moment] }}" loading="lazy" class="w-full rounded-lg border border-ink-800 bg-white">
                        @if ($capture->changed('desktop'))
                            <span class="absolute top-2 left-2 rounded bg-ink-950/85 px-1.5 py-0.5 text-[11px] text-ink-200">{{ LookalikeCapture::MOMENTS[$moment] }}</span>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    </figure>
@endif

@if ($mobile !== [])
    {{-- Celulares numa linha só: cada aparelho com 1 print (tela igual) ou 2 (mudou nos 10 s). --}}
    @php
        $phoneShots = [];
        foreach ($mobile as $device) {
            foreach (['first', 'second'] as $moment) {
                if ($capture->hasShot($device, $moment) && ($moment === 'first' || $capture->changed($device))) {
                    $phoneShots[] = [$device, $moment];
                }
            }
        }
    @endphp
    <div class="grid grid-cols-2 gap-3 {{ [3 => 'sm:grid-cols-3', 4 => 'sm:grid-cols-4'][count($phoneShots)] ?? 'sm:grid-cols-4' }}">
        @foreach ($phoneShots as [$device, $moment])
            <figure class="min-w-0 space-y-1.5">
                <figcaption class="text-xs">
                    <span class="font-semibold text-ink-200">{{ LookalikeCapture::DEVICES[$device] }}</span>
                    <span class="text-ink-400">· {{ $capture->changed($device) ? mb_strtolower(LookalikeCapture::MOMENTS[$moment]) : 'igual depois de 10 s' }}</span>
                </figcaption>
                <a href="{{ route('alerts.lookalikes.shot', [$capture, $device, $moment === 'second' ? 'depois' : 'first']) }}" target="_blank" rel="noopener" class="block">
                    <img src="{{ route('alerts.lookalikes.shot', [$capture, $device, $moment === 'second' ? 'depois' : 'first']) }}" alt="{{ LookalikeCapture::DEVICES[$device] }} · {{ LookalikeCapture::MOMENTS[$moment] }}" loading="lazy"
                         class="aspect-[9/16] w-full rounded-lg border border-ink-800 bg-white object-cover object-top">
                </a>
            </figure>
        @endforeach
    </div>
@endif
