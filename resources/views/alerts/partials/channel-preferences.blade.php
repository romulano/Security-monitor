{{--
    Preferências de um canal (Slack, Discord ou Telegram): tempo real, horário silencioso e resumo diário.
    Recebe $channel, $profiles, $timezones e $action (URL do PUT).
--}}
@php
    $switch = 'relative h-6 w-11 shrink-0 rounded-full bg-ink-700 transition peer-checked:bg-accent '
        .'peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent '
        .'after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-ink-200 after:transition '
        .'peer-checked:after:translate-x-5 peer-checked:after:bg-ink-950';
    $field = 'min-h-11 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent';
    $quietOn = (bool) old('quiet', $channel->quiet_start !== null);
    $days = array_map('intval', old('digest_days', $channel->digest_days ?? []));
@endphp
<form method="POST" action="{{ $action }}" class="space-y-4" data-channel-prefs>
    @csrf
    @method('PUT')

    <div>
        <h2 class="text-lg font-semibold text-white">O que receber</h2>
        <p class="text-sm text-ink-400">Os dois modos são independentes: ligue um, o outro ou os dois.</p>
    </div>

    <section class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5 has-[[name=realtime]:checked]:border-accent/40">
        <label class="flex cursor-pointer items-start justify-between gap-4">
            <span class="min-w-0">
                @if ($channel->isCompany())
                    {{-- O canal é da empresa (E5a2): fala dos alertas da empresa ou do setor, não da entrevista de uma pessoa. --}}
                    <span class="block font-semibold text-white">Alertas de Notícias, na hora</span>
                    <span class="mt-1 block text-sm text-ink-200">
                        Quando uma notícia casar com um alerta de Notícias {{ $channel->sector ? 'do setor '.$channel->sector->name : 'da empresa sem setor' }}, enviamos assim que a análise da IA termina.
                    </span>
                @else
                    <span class="block font-semibold text-white">Alertas da minha entrevista, na hora</span>
                    <span class="mt-1 block text-sm text-ink-200">
                        Quando uma notícia casar com {{ $profiles->count() > 1 ? 'um dos seus alertas' : 'o seu perfil ('.($profiles->first()?->levelLabel() ?? 'High e acima').', mais as menções à sua empresa)' }}, enviamos assim que a análise da IA termina.
                    </span>
                @endif
            </span>
            <input type="hidden" name="realtime" value="0">
            <input type="checkbox" name="realtime" value="1" @checked(old('realtime', $channel->realtime)) class="peer sr-only" data-reveals="#realtime-options">
            <span aria-hidden="true" class="{{ $switch }} mt-1"></span>
        </label>

        <div id="realtime-options" class="space-y-4 border-t border-ink-800 pt-4" @unless (old('realtime', $channel->realtime)) hidden @endunless>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-white">Horário silencioso</p>
                    <p class="text-sm text-ink-400">High e medium esperam o fim do silêncio. Critical sempre passa.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span id="quiet-options" class="flex flex-wrap items-center gap-2" @unless ($quietOn) hidden @endunless>
                        <label class="flex items-center gap-1.5 text-sm text-ink-400">das
                            <input type="time" name="quiet_start" value="{{ old('quiet_start', $channel->quiet_start ?? '22:00') }}" class="{{ $field }} [color-scheme:dark]">
                        </label>
                        <label class="flex items-center gap-1.5 text-sm text-ink-400">às
                            <input type="time" name="quiet_end" value="{{ old('quiet_end', $channel->quiet_end ?? '07:00') }}" class="{{ $field }} [color-scheme:dark]">
                        </label>
                    </span>
                    <label class="flex cursor-pointer items-center">
                        <span class="sr-only">Horário silencioso</span>
                        <input type="hidden" name="quiet" value="0">
                        <input type="checkbox" name="quiet" value="1" @checked($quietOn) class="peer sr-only" data-reveals="#quiet-options">
                        <span aria-hidden="true" class="{{ $switch }}"></span>
                    </label>
                </div>
            </div>
            @error('quiet_end') <p class="text-sm text-red-400">{{ $message }}</p> @enderror
            <p class="text-sm text-ink-400">
                Limite de {{ \App\Support\Settings::int('alerts.max_per_hour') }} mensagens por hora; o que passar disso chega agrupado numa só.
                Para mudar nível ou tecnologias, <a href="{{ route('alerts.news') }}" class="text-accent hover:underline">{{ $channel->isCompany() ? 'edite os alertas em Alertas' : 'edite a entrevista do alerta' }}</a>.
            </p>
        </div>
    </section>

    <section class="space-y-4 rounded-xl border border-ink-800 bg-ink-900 p-5 has-[[name=digest]:checked]:border-accent/40">
        <label class="flex cursor-pointer items-start justify-between gap-4">
            <span class="min-w-0">
                <span class="block font-semibold text-white">Resumo diário</span>
                <span class="mt-1 block text-sm text-ink-200">Tudo que entrou nas últimas 24 horas, numa mensagem só.</span>
            </span>
            <input type="hidden" name="digest" value="0">
            <input type="checkbox" name="digest" value="1" @checked(old('digest', $channel->digest)) class="peer sr-only" data-reveals="#digest-options">
            <span aria-hidden="true" class="{{ $switch }} mt-1"></span>
        </label>

        <div id="digest-options" class="space-y-4 border-t border-ink-800 pt-4" @unless (old('digest', $channel->digest)) hidden @endunless>
            <div class="flex flex-wrap gap-4">
                <label class="flex flex-col gap-1 text-sm text-ink-400">Horário
                    <input type="time" name="digest_time" value="{{ old('digest_time', $channel->digest_time) }}" class="{{ $field }} [color-scheme:dark]">
                </label>
                <label class="flex flex-col gap-1 text-sm text-ink-400">Fuso horário
                    <select name="timezone" class="{{ $field }} [color-scheme:dark]">
                        @foreach ($timezones as $value => $label)
                            <option value="{{ $value }}" @selected(old('timezone', $channel->timezone) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            @error('digest_time') <p class="text-sm text-red-400">{{ $message }}</p> @enderror

            <fieldset class="space-y-2">
                <legend class="text-sm text-ink-400">Conteúdo</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @php
                        $whose = $channel->sector ? 'o setor' : 'a empresa';
                        $scopes = $channel->isCompany()
                            ? ['all' => ['Tudo', "Panorama completo do dia, com o que afeta {$whose} no topo."], 'profile' => [$channel->sector ? 'Só os alertas do setor' : 'Só os alertas da empresa', 'Apenas os casos que casaram com eles.']]
                            : ['all' => ['Tudo', 'Panorama completo do dia, com o que afeta você no topo.'], 'profile' => ['Só o meu perfil', 'Apenas os casos que casaram com a sua entrevista.']];
                    @endphp
                    @foreach ($scopes as $value => [$label, $desc])
                        <label class="flex cursor-pointer flex-col gap-1 rounded-lg border border-ink-700 bg-ink-850 p-3.5 has-checked:border-accent has-checked:bg-accent/10">
                            <span class="flex items-center gap-2 text-sm font-semibold text-ink-200">
                                <input type="radio" name="digest_scope" value="{{ $value }}" @checked(old('digest_scope', $channel->digest_scope) === $value) class="size-4 accent-accent">
                                {{ $label }}
                            </span>
                            <span class="text-xs text-ink-400">{{ $desc }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="text-sm text-ink-400">Dias</legend>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Models\NotificationChannel::DAYS as $n => $label)
                        <label class="inline-flex min-h-11 min-w-13 cursor-pointer items-center justify-center rounded-lg border border-ink-700 bg-ink-850 px-2.5 text-sm text-ink-200
                                      has-checked:border-accent has-checked:bg-accent/10 has-checked:text-amber-200 has-focus-visible:outline-2 has-focus-visible:outline-accent">
                            <input type="checkbox" name="digest_days[]" value="{{ $n }}" @checked(in_array($n, $days, true)) class="sr-only">{{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <p class="text-sm text-ink-400">Casos que já chegaram na hora aparecem no resumo marcados como “já enviado”.</p>
        </div>
    </section>

    @if (! $channel->realtime && ! $channel->digest)
        <div class="rounded-xl border border-accent/40 bg-accent/5 px-4 py-3 text-sm text-ink-200">
            Com os dois desligados, nada vai para o {{ $channel->appName() }}. Os alertas continuam aparecendo em Alertas.
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-end gap-3">
        <button class="inline-flex min-h-11 items-center rounded-lg bg-accent px-5 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar preferências</button>
    </div>
</form>
