@extends('layouts.app')

@section('title', 'Domínios da empresa · Security Monitor')

@section('content')
    @php
        $input = 'min-h-11 w-full min-w-0 rounded-lg border border-ink-800 bg-ink-900 px-3 text-sm focus:border-accent';
        $caption = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $full = $used >= $limit;
        $mono = 'font-mono [overflow-wrap:anywhere]';
        $date = fn ($value) => $value?->timezone('America/Sao_Paulo')->format('d/m/Y');
    @endphp

    <div class="mx-auto max-w-3xl space-y-6 py-6">
        <div>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-lg font-semibold text-white">Domínios de {{ $company->name }}</h1>
                @include('company.partials.nav')
            </div>
            <p class="text-sm text-ink-400">
                @if ($canAdd)
                    {{ $used }} de {{ $limit }} domínios.
                @endif
                Um domínio comprovado aqui vale para os alertas de Notícias e os Radares da empresa, e para os subdomínios dele:
                ninguém comprova de novo. Quem está no setor de um domínio o vê; sem setor, a empresa toda vê.
                @unless ($canAdd)
                    Quem cadastra e comprova é quem administra a empresa ou quem responde pelo setor do domínio. Peça a uma dessas pessoas.
                @endunless
            </p>
        </div>

        @if ($canAdd)
            <form method="POST" action="{{ route('company.domains.store') }}" class="space-y-3 rounded-lg border border-ink-800 bg-ink-900 p-4">
                @csrf
                <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                    <label class="block">
                        <span class="{{ $caption }}">Domínio</span>
                        <input id="dominio" name="dominio" required maxlength="255" value="{{ old('dominio') }}" placeholder="empresa.com.br"
                               autocomplete="off" spellcheck="false" class="{{ $input }}" @disabled($full)
                               @error('dominio') aria-invalid="true" aria-describedby="dominio-error" @enderror>
                    </label>
                    <button class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110 disabled:opacity-50" @disabled($full)>Cadastrar domínio</button>
                </div>
                @error('dominio')<p id="dominio-error" class="text-sm text-red-300">{{ $message }}</p>@enderror
                @include('alerts.partials.sector-choice', [
                    'sectors' => $sectorChoices, 'sectorless' => $sectorless, 'what' => 'o domínio',
                    'hint' => 'Quem está no setor vê o domínio; quem administra a empresa vê todos.'.($sectorless ? ' Sem setor, toda a empresa vê.' : '').' Dá para trocar depois.',
                ])
                @if ($full)
                    <p class="text-xs text-ink-400">A empresa chegou ao limite de {{ $limit }} domínios. {{ $sectorless ? 'Para cadastrar outro, remova um domínio. Precisa de mais? Escreva para contato@example.com.' : 'Para cadastrar outro, remova um domínio do seu setor ou fale com quem administra a empresa.' }}</p>
                @endif
            </form>
        @endif

        @forelse ($domains as $owned)
            @php
                $state = $owned->ownership;
                $mine = (string) old('_remover') === (string) $owned->id;
            @endphp
            <section id="dominio-{{ $owned->id }}" class="space-y-3 rounded-lg border border-ink-800 bg-ink-900 p-4" data-domain="{{ $owned->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="min-w-0 text-sm font-semibold text-white {{ $mono }}">{{ $owned->domain }}</h2>
                    @include('alerts.partials.sector-badge', ['item' => $owned])
                </div>

                @if ($owned->manages && $state['state'] === 'proven')
                    {{-- Comprovado: uma linha só; o topo já diz para que serve (Designer, rodada 12). --}}
                    <p class="flex flex-wrap items-center gap-2 text-sm text-ink-200">
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-800 bg-emerald-950/60 px-2.5 py-0.5 text-xs text-emerald-300">
                            <span class="size-1.5 rounded-full bg-emerald-400"></span>posse comprovada
                        </span>
                        <span class="min-w-0">
                            Posse comprovada em {{ $date($state['verifiedAt']) }} pelo TXT em <span class="{{ $mono }}">{{ $state['proofName'] }}</span>.
                            Conferimos uma vez por dia; se o registro sumir por {{ config('secmon.ownership.grace_days') }} dias, a posse cai.
                        </span>
                    </p>
                @elseif ($owned->manages)
                    <x-domain-ownership :state="$state" :action="route('company.domains.verify', $owned)" :field="'txt-'.$owned->id">
                        Vale para os alertas de Notícias e os Radares da empresa sobre este domínio e os subdomínios dele.
                    </x-domain-ownership>
                @else
                    {{-- Quem só vê: o estado da posse, sem o TXT da empresa (P10). --}}
                    <p class="text-sm text-ink-400" data-domain-state="{{ $state['state'] }}">
                        @switch($state['state'])
                            @case('proven')
                                Posse comprovada em {{ $date($state['verifiedAt']) }} pelo TXT em <span class="{{ $mono }}">{{ $state['proofName'] }}</span>.
                                @break
                            @case('unseen')
                                O TXT sumiu do DNS em {{ $date($state['unseenSince']) }}. Quem configura o domínio precisa publicar o registro de novo.
                                @break
                            @default
                                Aguardando o registro TXT. Quem configura o domínio vê o registro e comprova.
                        @endswitch
                    </p>
                @endif

                @if ($owned->coveredBy)
                    {{-- O nome antigo cadastrado como domínio embaixo de outro da empresa (Designer, rodada 13). --}}
                    <p class="text-xs text-amber-300">Já coberto por {{ $owned->coveredBy }}: dá para remover e cadastrar como subdomínio, liberando a vaga.</p>
                @endif

                @if ($owned->manages)
                    @include('alerts.partials.sector-change', [
                        'item' => $owned, 'action' => route('company.domains.sector', $owned), 'mutedIn' => [], 'blocked' => $owned->blocked,
                        'subject' => 'o domínio '.$owned->domain, 'short' => 'este domínio', 'things' => 'a posse dele',
                    ])
                @endif

                {{-- Os subdomínios (E3b): a posse comprovada já os cobre; antes dela, só os que já existem. --}}
                @if ($owned->isVerified() || $owned->subs['active']->isNotEmpty())
                    @include('company.partials.subdomains', ['domain' => $owned, 'subs' => $owned->subs])
                @endif

                @if ($owned->manages)

                    @php
                        $counts = $owned->dependents;
                        $summary = \App\Services\Company\CompanyDomains::summary($counts);
                    @endphp
                    <details class="border-t border-ink-800 pt-3" @if ($mine) open @endif>
                        <summary class="cursor-pointer text-xs text-red-300 hover:underline">Remover este domínio</summary>
                        <form method="POST" action="{{ route('company.domains.remove') }}" class="mt-3 space-y-3" data-confirm-tone="danger"
                              data-confirm="Remover {{ $owned->domain }} da empresa? {{ $summary }}" data-confirm-ok="Remover {{ $owned->domain }}">
                            @csrf
                            @method('DELETE')
                            {{-- O domínio e a contagem que o diálogo mostrou: o servidor confere de novo antes de remover. --}}
                            <input type="hidden" name="dominio" value="{{ implode('-', [$owned->id, ...array_values($counts)]) }}">
                            <input type="hidden" name="_remover" value="{{ $owned->id }}">
                            <p class="text-sm text-ink-400">
                                Remover libera uma vaga no limite e tira a posse do domínio e dos subdomínios dele de tudo o que a empresa
                                monitora. O registro TXT continua no DNS: com ele, dá para cadastrar e comprovar de novo depois.
                            </p>
                            <p class="text-sm text-ink-200">{{ $summary }}</p>
                            @if ($mine)
                                @error('dominio')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                            @endif
                            @include('account.partials.reauth-inline', ['suffix' => '-remover-'.$owned->id, 'quiet' => ! $mine])
                            <button class="min-h-11 rounded-lg border border-red-900/60 px-4 text-sm text-red-300 hover:border-red-500">Remover {{ $owned->domain }}</button>
                        </form>
                    </details>
                @endif
            </section>
        @empty
            <p class="rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm text-ink-400">
                @if ($canAdd)
                    A empresa ainda não tem domínios aqui. Cadastre o primeiro acima: depois de comprovado, ele vale para os alertas e Radares da empresa.
                @else
                    Nenhum domínio dos seus setores ainda.
                @endif
            </p>
        @endforelse

        @if ($domains->contains('manages', true))
            {{-- O segundo passo da confirmação (o código do app, com a 2FA), fora dos formulários dos cartões. --}}
            @include('account.partials.reauth', ['twoFactorBox' => false])
        @endif
    </div>
@endsection
