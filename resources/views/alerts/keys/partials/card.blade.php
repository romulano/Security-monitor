{{--
    O cartão "Chaves expostas" da Integração: para toda conta. Sem nenhum domínio, a pílula diz isso e o botão
    leva à página, onde dá para adicionar um.
    Espera $card (KeysPanel::card()), $pills e $primary da página.
--}}
@php
    $pill = match (true) {
        $card['proven'] === 0 => ['sem domínio', 'off'],
        $card['active'] > 0 => [$card['active'] === 1 ? 'ativa em 1 domínio' : 'ativa em '.$card['active'].' domínios', 'on'],
        default => ['não ativada', 'off'],
    };
@endphp
<section aria-labelledby="chaves-titulo" class="flex flex-wrap items-center gap-6 rounded-xl border border-ink-800 bg-ink-900 p-5" data-source="keys">
    <div class="flex min-w-0 flex-[1_1_420px] flex-col gap-4">
        <div class="flex items-center gap-3">
            <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-amber-300/10 text-amber-300" aria-hidden="true">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
            </span>
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="chaves-titulo" class="text-lg font-semibold text-white"><a href="{{ route('alerts.keys') }}" class="hover:text-accent">Chaves expostas</a></h2>
                <span class="inline-flex w-fit items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs {{ $pills[$pill[1]][0] }}"><span class="size-1.5 rounded-full {{ $pills[$pill[1]][1] }}"></span>{{ $pill[0] }}</span>
            </div>
        </div>
        <p class="text-sm text-ink-200">
            Chaves de API, tokens e senhas esquecidos nas páginas e nos scripts do seu site. O Security Monitor navega como um buscador e você diz se cada chave pode ficar exposta.
        </p>
        <div><a href="{{ route('alerts.keys') }}" class="{{ $primary }}">{{ $card['active'] > 0 ? 'Abrir as chaves' : 'Ver os domínios' }}</a></div>
    </div>
    <div class="flex min-w-0 flex-[1_1_380px] flex-col gap-2">
        @if ($card['active'] > 0 || $card['open'] > 0 || $card['leak'] > 0)
            <dl class="grid grid-cols-3 divide-x divide-ink-800 rounded-lg border border-ink-800 bg-ink-950/40">
                @foreach ([[$card['active'], $card['active'] === 1 ? 'domínio varrido' : 'domínios varridos', 'text-white'], [$card['open'], 'para decidir', 'text-accent'], [$card['leak'], 'não deveriam estar expostas', 'text-red-300']] as [$value, $label, $tone])
                    <div class="flex min-w-0 flex-col-reverse px-3 py-2.5">
                        <dt class="text-xs leading-4 text-ink-400">{{ $label }}</dt>
                        <dd class="truncate font-mono text-lg font-semibold {{ $value ? $tone : 'text-white' }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="text-xs text-ink-400">Última varredura: {{ $card['last'] ? $card['last']->timezone('America/Sao_Paulo')->format('d/m H:i') : 'ainda não' }}.</p>
        @else
            <ul class="list-disc space-y-0.5 pl-5 text-sm text-ink-400">
                <li>Nos domínios que você cadastrar</li>
                <li>Só leitura e devagar; repete toda semana</li>
                <li>Nunca guarda nem usa a chave: só o começo, o fim e o tamanho</li>
            </ul>
        @endif
    </div>
</section>
