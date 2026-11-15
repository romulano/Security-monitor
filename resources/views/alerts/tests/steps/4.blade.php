{{-- Passo 4 (Autorizar-4): a janela, a intensidade e o modo do firewall. Os números entram no termo do passo 6. --}}
@php
    use App\Models\NotificationChannel;
    use App\Services\Pentest\PentestBudget;
    use App\Services\Pentest\PentestWindow;

    $days = array_map('intval', (array) old('dias', $window['days']));
    $mode = old('comecar', $window['start_mode']);
    $chosen = old('intensidade', $intensity);
    $firewall = old('firewall', $waf);
    $number = fn ($value) => number_format($value, floor($value) == $value ? 0 : 1, ',', '.');
    $hours = fn (int $minutes) => intdiv($minutes, 60).' '.(intdiv($minutes, 60) === 1 ? 'hora' : 'horas');
    $active = PentestBudget::PRESETS[$intensity]['max_active_minutes'];
    $box = 'space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5';
    $legend = 'text-[11px] tracking-widest text-ink-400 uppercase';
    $field = 'min-h-11 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent';
    $radio = 'flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5';
    $error = fn (string $key) => $errors->first($key);
    $presets = [
        'padrao' => fn ($p) => "Até {$number($p['rps'])} requisições por segundo por site ({$number($p['rps_shared'])} em hospedagem compartilhada); mapeamento a {$number($p['rps_mapping'])} por segundo; portas a {$number($p['ports_cps'])} conexões por segundo por IP, só em servidor dedicado.",
        'leve' => fn ($p) => "Metade de tudo: até {$number($p['rps'])} requisições por segundo por site ({$number($p['rps_shared'])} em hospedagem compartilhada) e portas a {$number($p['ports_cps'])} conexões por segundo. Leva mais tempo e pode deixar itens sem orçamento.",
    ];
@endphp

<div>
    <h2 class="text-lg font-semibold text-white">Quando e com que intensidade</h2>
    <p class="text-sm text-ink-400">Os números desta página entram no termo do passo 6, e o Security Monitor nunca passa deles.</p>
</div>

<form id="step-form" method="POST" action="{{ route('alerts.tests.authorize.update', ['passo' => 4, 'dominio' => $domain->host]) }}" class="space-y-4">
    @csrf
    @method('PUT')

    <section class="{{ $box }}">
        <h3 class="text-[15px] font-semibold text-white">Quando</h3>
        <p class="text-sm text-ink-200">
            {{ ucfirst(PentestWindow::describe($window)) }}. O teste dura até {{ $hours($active) }} de atividade; se a janela fechar antes, ele continua no próximo horário permitido.
        </p>

        <fieldset class="space-y-2">
            <legend class="{{ $legend }}">Dias</legend>
            <div class="flex flex-wrap gap-2">
                @foreach (NotificationChannel::DAYS as $n => $label)
                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-ink-700 bg-ink-850 px-3 has-checked:border-accent has-checked:bg-accent/5">
                        <input type="checkbox" name="dias[]" value="{{ $n }}" @checked(in_array($n, $days, true)) class="size-4 accent-accent">
                        <span class="text-sm text-white">{{ mb_strtolower($label) }}</span>
                    </label>
                @endforeach
            </div>
            <label class="flex items-start gap-2.5 text-sm text-ink-200">
                <input type="checkbox" name="fim_de_semana" value="1" @checked(old('fim_de_semana', (bool) array_intersect($window['days'], [6, 7]))) class="mt-0.5 size-4 shrink-0 accent-accent">
                Se marcar sábado ou domingo: confirmo que alguém do time vai estar de olho para apertar Parar, se precisar.
            </label>
            @foreach (['dias', 'dias.*', 'fim_de_semana'] as $key)
                @if ($error($key))<p class="text-sm text-red-300">{{ $error($key) }}</p>@endif
            @endforeach
        </fieldset>

        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-sm text-ink-200">Das
                <input type="time" name="inicio" value="{{ old('inicio', $window['start']) }}" class="{{ $field }}">
            </label>
            <label class="flex flex-col gap-1 text-sm text-ink-200">às
                <input type="time" name="fim" value="{{ old('fim', $window['end']) }}" class="{{ $field }}">
            </label>
            <label class="flex flex-col gap-1 text-sm text-ink-200">Fuso horário
                <select name="fuso" class="{{ $field }}">
                    @foreach (PentestWindow::ZONES as $zone => $name)
                        <option value="{{ $zone }}" @selected(old('fuso', $window['tz']) === $zone)>{{ NotificationChannel::TIMEZONES[$zone] ?? $zone }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm text-ink-200">Válida até
                <input type="date" name="validade" value="{{ old('validade', $window['valid_until']) }}" class="{{ $field }}">
            </label>
        </div>
        @foreach (['inicio', 'fim', 'fuso', 'validade'] as $key)
            @if ($error($key))<p class="text-sm text-red-300">{{ $error($key) }}</p>@endif
        @endforeach

        @if ($ruler)
            <div class="space-y-1.5 pt-1">
                <p class="flex flex-wrap justify-between gap-2 text-xs text-ink-400">
                    <span>Horário de Brasília (America/Sao_Paulo)</span>
                    <span class="font-mono text-ink-200">{{ $ruler['text'] }}</span>
                </p>
                <div aria-hidden="true" class="relative h-8 overflow-hidden rounded bg-ink-850">
                    <div class="absolute inset-y-0 left-0 w-1/6 bg-red-950/70"></div>
                    <div class="absolute inset-y-1 rounded bg-accent/80" style="left: {{ round($ruler['start'] / 14.4, 3) }}%; width: {{ round(($ruler['end'] - $ruler['start']) / 14.4, 3) }}%"></div>
                </div>
                <div aria-hidden="true" class="flex justify-between font-mono text-[11px] text-ink-400">
                    @foreach (['00', '03', '06', '09', '12', '15', '18', '21', '24'] as $tick)<span>{{ $tick }}</span>@endforeach
                </div>
                <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-400">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-950"></span>Manutenção do Security Monitor · 00:00–04:00 em Brasília: sem testes</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-accent"></span>Sua janela</span>
                </p>
                <p class="text-xs text-ink-400">
                    A janela inteira tem de ficar fora das 00:00–04:00 (horário de Brasília).
                    @if ($window['tz'] !== PentestWindow::NIGHT_ZONE)
                        A régua mostra a janela salva ({{ $window['start'] }}–{{ $window['end'] }}, {{ PentestWindow::ZONES[$window['tz']] }}) no horário de Brasília.
                    @else
                        A régua mostra a janela já salva.
                    @endif
                </p>
            </div>
        @endif
    </section>

    <fieldset class="{{ $box }}">
        <legend class="sr-only">Começar</legend>
        <h3 class="text-[15px] font-semibold text-white" aria-hidden="true">Começar</h3>
        <label class="{{ $radio }}">
            <input type="radio" name="comecar" value="next" @checked($mode !== 'date') class="mt-0.5 size-5 shrink-0 accent-accent">
            <span class="text-sm text-white">Na próxima janela depois da aprovação</span>
        </label>
        <label class="{{ $radio }}">
            <input type="radio" name="comecar" value="date" @checked($mode === 'date') class="mt-0.5 size-5 shrink-0 accent-accent">
            <span class="flex flex-1 flex-wrap items-center gap-3 text-sm text-white">
                A partir de uma data
                <input type="date" name="inicio_data" aria-label="Data de início" value="{{ old('inicio_data', $window['start_mode'] === 'date' ? $window['valid_from'] : '') }}" class="{{ $field }}">
            </span>
        </label>
        @if ($error('inicio_data'))<p class="text-sm text-red-300">{{ $error('inicio_data') }}</p>@endif
        @if ($error('comecar'))<p class="text-sm text-red-300">{{ $error('comecar') }}</p>@endif
        <p class="text-xs text-ink-400">Um teste típico leva de 30 a 40 minutos (estimativa, a confirmar no piloto).</p>
    </fieldset>

    <fieldset class="{{ $box }}">
        <legend class="sr-only">Intensidade</legend>
        <h3 class="text-[15px] font-semibold text-white" aria-hidden="true">Intensidade</h3>
        @foreach (PentestBudget::PRESETS as $key => $preset)
            <label class="{{ $radio }}">
                <input type="radio" name="intensidade" value="{{ $key }}" @checked($chosen === $key) class="mt-0.5 size-5 shrink-0 accent-accent">
                {{-- O nome sozinho na primeira linha do cartão, como no canvas. --}}
                <span class="flex flex-col gap-1">
                    <span class="text-sm font-semibold text-white">{{ $preset['label'] }}</span>
                    <span class="text-sm text-ink-200">{{ ($presets[$key] ?? fn () => '')($preset) }}</span>
                    <span class="text-xs text-ink-400">até {{ $number($preset['max_requests']) }} requisições · até {{ $hours($preset['max_active_minutes']) }} de atividade</span>
                </span>
            </label>
        @endforeach
        @if ($error('intensidade'))<p class="text-sm text-red-300">{{ $error('intensidade') }}</p>@endif
        <p class="text-xs text-ink-400">A rede do Security Monitor corta qualquer pico acima do dobro desses ritmos.</p>
    </fieldset>

    <fieldset class="{{ $box }}">
        <legend class="sr-only">Como o teste vê o seu site</legend>
        <h3 class="text-[15px] font-semibold text-white" aria-hidden="true">Como o teste vê o seu site</h3>
        <label class="{{ $radio }}">
            <input type="radio" name="firewall" value="on" @checked($firewall !== 'release') class="mt-0.5 size-5 shrink-0 accent-accent">
            <span class="text-sm text-ink-200">
                <b class="text-white">Com o firewall ligado (recomendado).</b> Vemos o que qualquer visitante vê. Se o firewall barrar o Security Monitor,
                paramos de testar aquele endereço e o relatório mostra o que ficou de fora.
            </span>
        </label>
        <label class="{{ $radio }}">
            <input type="radio" name="firewall" value="release" @checked($firewall === 'release') class="mt-0.5 size-5 shrink-0 accent-accent">
            <span class="text-sm text-ink-200">
                <b class="text-white">Com uma liberação restrita, só para este teste.</b> Você cria uma regra que libera só o IP do Security Monitor com o cabeçalho
                deste teste, só durante a janela. Os testes e o ritmo são os mesmos; só o filtro do firewall sai do caminho. A regra pronta aparece no passo 5.
            </span>
        </label>
        @if ($error('firewall'))<p class="text-sm text-red-300">{{ $error('firewall') }}</p>@endif
    </fieldset>

    <p class="rounded-xl border border-ink-800 px-5 py-4 text-sm text-ink-200">
        <b class="text-white">Proteção do site.</b> Pausamos sozinhos se o site começar a responder com erro (5xx) ou ficar lento. Se o firewall bloquear o
        Security Monitor, paramos de testar aquele endereço em segundos (no laboratório, 5 a 7 requisições depois do bloqueio) e ele aparece como “não testado”,
        nunca como “sem achados”.
    </p>
</form>
