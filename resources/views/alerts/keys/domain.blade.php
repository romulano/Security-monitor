@extends('layouts.app')

@section('title', 'Chaves · '.$owned->domain.' · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    $tz = 'America/Sao_Paulo';
    $box = 'space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5';
    $button = 'inline-flex min-h-11 items-center rounded-lg border px-4 text-sm';
    $open = collect($keys)->whereNull('verdict')->values();
    $decided = collect($keys)->whereNotNull('verdict')->values();
    $summary = $site?->last_summary;
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                @include('alerts.partials.crumbs', ['parent' => ['Chaves expostas', route('alerts.keys')], 'here' => $owned->domain])
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-semibold text-white">Chaves no site · <span class="inline-block max-w-full font-mono text-lg [overflow-wrap:anywhere]">{{ $owned->domain }}</span></h1>
                    @include('alerts.tests.partials.pill', ['pill' => $state, 'pulse' => $site?->isBusy() ?? false])
                </div>
                <p class="max-w-3xl text-sm text-ink-300">
                    Com a posse comprovada como autorização, a varredura navega o site inteiro (só leitura e devagar, inclusive o que o robots.txt esconde dos buscadores) e procura chaves de API, tokens e senhas
                    nas páginas e nos scripts. Algumas são feitas para ficar no navegador; outras nunca deveriam estar lá. Diga o que cada uma é: a decisão
                    fica para as próximas varreduras. O Security Monitor nunca guarda nem usa a chave: só o começo, o fim e o tamanho.
                </p>
            </div>
            @if ($manageable)
                @include('alerts.keys.partials.actions', ['owned' => $owned, 'site' => $site])
            @endif
        </div>

        @if ($site?->scan_status === 'running' && is_array($site->scan_state))
            <p class="text-sm text-ink-200" role="status">
                Varrendo agora: {{ $site->scan_state['read'] ?? 0 }} páginas e {{ $site->scan_state['js'] ?? 0 }} scripts lidos até aqui.
                A varredura vai em etapas (até 1.000 páginas e 1.000 scripts) e pode levar até 1 hora; as chaves aparecem aqui conforme ela anda.
            </p>
        @endif

        @if ($site?->last_scan_at)
            <p class="text-sm text-ink-400">
                Última varredura em {{ $site->last_scan_at->timezone($tz)->format('d/m \à\s H:i') }}@if ($summary): {{ $summary['pages'] }} {{ $summary['pages'] === 1 ? 'página' : 'páginas' }} e {{ $summary['scripts'] ?? 0 }} {{ ($summary['scripts'] ?? 0) === 1 ? 'script lido' : 'scripts lidos' }} em {{ $summary['hosts'] }} {{ $summary['hosts'] === 1 ? 'nome' : 'nomes' }}{{ ($summary['sitemap_urls'] ?? 0) > 0 ? ', com '.$summary['sitemap_urls'].' endereços do sitemap' : '' }}{{ ($summary['archive_urls'] ?? 0) > 0 ? ' e '.$summary['archive_urls'].' do Web Archive' : '' }}{{ $summary['truncated'] ? ' (parou no limite de páginas, de scripts ou de tempo)' : '' }}@endif.
                @if ($site->last_error) <span class="text-amber-200">{{ $site->last_error }}</span> @endif
                @if ($site->isActive() && $site->next_scan_at) A próxima sai em {{ $site->next_scan_at->timezone($tz)->format('d/m') }}. @endif
            </p>
        @endif

        @if ($manageable)
            @php($extra = (array) ($site?->extra_paths ?? []))
            <details class="{{ $box }}" @if ($errors->has('paths') || $extra !== []) open @endif>
                <summary class="cursor-pointer text-sm font-semibold text-white">Endereços extras{{ $extra !== [] ? ' ('.count($extra).')' : '' }}</summary>
                <p class="text-sm text-ink-300">
                    A varredura acha as páginas pelos links, pelos sitemaps, pelo histórico do Web Archive e pelos caminhos citados nos scripts. A página que
                    nenhum desses mostra (um app em /app/…, um fluxo de cadastro ou de verificação), liste aqui: ela é visitada antes de tudo, toda vez.
                    Um por linha, o caminho (<span class="font-mono">/app/facial-auth/</span>) ou o endereço inteiro, do domínio ou de um subdomínio; até 50.
                </p>
                <form method="POST" action="{{ route('alerts.keys.paths', ['dominio' => $owned->domain]) }}" class="space-y-2">
                    @csrf
                    @method('PUT')
                    <label for="enderecos" class="sr-only">Endereços extras, um por linha</label>
                    <textarea id="enderecos" name="paths" rows="5" placeholder="/app/facial-auth/&#10;/redefine/facial-auth"
                              class="w-full rounded-lg border border-ink-700 bg-ink-850 px-3 py-2 font-mono text-sm text-white focus:border-accent">{{ old('paths', implode("\n", $extra)) }}</textarea>
                    @error('paths')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                    <button class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400 hover:text-white">Salvar os endereços</button>
                </form>
            </details>
        @endif

        @if ($counts['total'] === 0)
            <section class="{{ $box }}">
                <p class="text-sm text-ink-200">{{ $site === null ? 'A varredura deste domínio ainda não foi ativada.' : 'Nenhuma chave achada até agora.' }}</p>
            </section>
        @else
            <p class="text-sm text-ink-300">{{ $counts['total'] }} {{ $counts['total'] === 1 ? 'chave' : 'chaves' }}: {{ $counts['open'] }} para decidir, {{ $counts['leak'] }} {{ $counts['leak'] === 1 ? 'que não deveria estar exposta' : 'que não deveriam estar expostas' }} e {{ $counts['allowed'] }} {{ $counts['allowed'] === 1 ? 'que pode ficar' : 'que podem ficar' }}.</p>
        @endif

        @foreach ([['para-decidir', 'Para decidir', $open], ['decididas', 'Decididas', $decided]] as [$id, $heading, $list])
            @if ($list->isNotEmpty())
                <section aria-labelledby="{{ $id }}" class="space-y-3">
                    <h2 id="{{ $id }}" class="text-[15px] font-semibold text-white">{{ $heading }}</h2>
                    <ul class="space-y-3">
                        @foreach ($list as $item)
                            @php($key = $item['key'])
                            <li id="chave-{{ $key->id }}" class="{{ $box }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-white">{{ $item['label'] }}</h3>
                                    @if ($item['verdict'])
                                        @include('alerts.tests.partials.pill', ['pill' => $item['verdict']])
                                    @else
                                        <span class="text-xs text-ink-400">sugestão do Security Monitor:</span>
                                        @include('alerts.tests.partials.pill', ['pill' => $item['suggestion']])
                                    @endif
                                </div>
                                <p class="font-mono text-sm [overflow-wrap:anywhere] text-white">
                                    {{ $key->masked }}@if ($key->name)<span class="text-ink-400"> · no nome </span>{{ $key->name }}@endif
                                </p>
                                <p class="text-sm text-ink-300">{{ $item['hint'] }}</p>
                                <div class="space-y-1 text-xs text-ink-400">
                                    <p>Onde apareceu{{ $key->occurrences > 1 ? ' ('.$key->occurrences.' vezes)' : '' }}:</p>
                                    <ul class="space-y-0.5 font-mono break-all text-ink-200">
                                        @foreach ($key->locations as $place)
                                            @if (isset($place['url']))
                                                <li><a href="{{ $place['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="hover:text-accent hover:underline">{{ $place['url'] }}</a> <span class="text-ink-400">· linha {{ $place['line'] }}, coluna {{ $place['col'] }}</span></li>
                                            @else
                                                <li>{{ $place['host'] }}{{ $place['path'] }}{{ $place['count'] > 1 ? ' · '.$place['count'].'×' : '' }}</li>
                                            @endif
                                        @endforeach
                                    </ul>
                                    <p>
                                        Vista pela primeira vez em {{ $key->first_seen_at->timezone($tz)->format('d/m') }}, pela última em {{ $key->last_seen_at->timezone($tz)->format('d/m \à\s H:i') }}{{ $key->source === 'pentest' ? ', no teste de segurança' : '' }}.
                                    </p>
                                    @if ($item['gone'])
                                        <p class="text-emerald-300">A última varredura não a achou: ela pode ter saído do site.</p>
                                    @endif
                                </div>
                                @if ($key->verdict === 'leak')
                                    <p class="rounded-lg border border-red-900 bg-red-950/30 px-3 py-2 text-sm text-red-100">
                                        Troque a chave no serviço que a emitiu (revogue a antiga) e tire-a do código do site. Quem já a copiou continua com ela até ser revogada.
                                    </p>
                                @endif
                                @if ($manageable)
                                <div class="flex flex-wrap gap-2">
                                    @if ($key->verdict === null)
                                        @foreach ([['allowed', 'Pode ficar exposta', 'border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white'], ['leak', 'Não deveria estar exposta', 'border-red-900 text-red-300 hover:border-red-400']] as [$value, $text, $tone])
                                            <form method="POST" action="{{ route('alerts.keys.classify', ['dominio' => $owned->domain, 'chave' => $key->id]) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="verdict" value="{{ $value }}">
                                                <button class="{{ $button }} {{ $tone }}">{{ $text }}</button>
                                            </form>
                                        @endforeach
                                    @else
                                        <form method="POST" action="{{ route('alerts.keys.classify', ['dominio' => $owned->domain, 'chave' => $key->id]) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="verdict" value="">
                                            <button class="{{ $button }} border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white">Desfazer a decisão</button>
                                        </form>
                                    @endif
                                </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach
    </div>
@endsection
