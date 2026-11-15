@extends('layouts.app')

@section('title', 'Setores da empresa · Security Monitor')

@section('content')
    @php
        $input = 'min-h-11 w-full min-w-0 rounded-lg border border-ink-800 bg-ink-900 px-3 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $button = 'min-h-11 shrink-0 rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-accent';
        $full = $used >= $limit;
    @endphp

    <div class="mx-auto max-w-3xl space-y-6 py-6">
        <div>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-lg font-semibold text-white">Setores de {{ $company->name }}</h1>
                @include('company.partials.nav')
            </div>
            <p class="text-sm text-ink-400">
                {{ $used }} de {{ $limit }} setores. Uma conta pode estar em vários setores, e quem administra a empresa responde
                por todos eles, sem precisar estar em cada um. Por enquanto, pôr alguém num setor não muda o que a pessoa vê:
                separar domínios, alertas e canais por setor vem nas próximas versões.
            </p>
            <p class="mt-2 text-sm text-ink-400">
                Responsável do setor cuida do setor junto com você. Para marcar alguém, mude antes o papel da conta para
                Responsável do setor em <a href="{{ route('company.accounts') }}" class="text-accent hover:underline">Contas</a>;
                aí aparece aqui o botão Marcar como responsável. Se a conta voltar a Membro, deixa de responder pelos setores.
            </p>
        </div>

        <form method="POST" action="{{ route('company.sectors.store') }}" class="grid gap-3 rounded-lg border border-ink-800 bg-ink-900 p-4 sm:grid-cols-[1fr_auto] sm:items-end">
            @csrf
            <label class="block">
                <span class="{{ $label }}">Nome do setor</span>
                @php $createError = ! old('_sector') && $errors->has('name'); @endphp
                <input id="sector-name" name="name" required maxlength="{{ \App\Services\Company\Sectors::NAME_MAX }}" value="{{ old('_sector') ? '' : old('name') }}"
                       placeholder="Financeiro, TI, Marketing…" class="{{ $input }}" @disabled($full)
                       @if ($createError) aria-invalid="true" aria-describedby="sector-name-error" @endif>
            </label>
            <button class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110 disabled:opacity-50" @disabled($full)>Criar setor</button>
            @if ($createError)
                <p id="sector-name-error" class="text-sm text-red-300 sm:col-span-2">{{ $errors->first('name') }}</p>
            @endif
            @if ($full)
                <p class="text-xs text-ink-400 sm:col-span-2">A empresa chegou ao máximo de setores. Para criar outro, apague um que não use mais. Precisa de mais? Escreva para contato@example.com.</p>
            @endif
        </form>

        @forelse ($sectors as $sector)
            @php
                $inside = $sector->users;
                $managers = $inside->filter(fn ($user) => $user->pivot->is_manager);
                $outside = $accounts->reject(fn ($member) => $inside->contains('id', $member->user_id));
            @endphp
            <section class="rounded-lg border border-ink-800 bg-ink-900 p-4" data-sector="{{ $sector->id }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="min-w-0 text-sm font-semibold break-words text-white">{{ $sector->name }}</h2>
                    <span class="text-xs text-ink-400">{{ $inside->count() }} {{ $inside->count() === 1 ? 'conta' : 'contas' }}</span>
                </div>
                <p class="mt-1 text-xs text-ink-400">
                    @php $people = collect([($admin?->name ?? 'Quem administra').' (admin da empresa, responde por todos)'])->merge($managers->pluck('name')); @endphp
                    Responde pelo setor: {{ $people->join(', ', ' e ') }}.
                </p>

                <details class="mt-3" @if (old('_sector') == $sector->id) open @endif>
                    <summary class="cursor-pointer text-xs text-accent hover:underline">Renomear</summary>
                    <form method="POST" action="{{ route('company.sectors.rename', $sector) }}" class="mt-2 flex gap-2">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_sector" value="{{ $sector->id }}">
                        <label class="sr-only" for="name-{{ $sector->id }}">Novo nome do setor {{ $sector->name }}</label>
                        @php $renameError = old('_sector') == $sector->id && $errors->has('name'); @endphp
                        <input id="name-{{ $sector->id }}" name="name" required maxlength="{{ \App\Services\Company\Sectors::NAME_MAX }}"
                               value="{{ old('_sector') == $sector->id ? old('name') : $sector->name }}" class="{{ $input }}"
                               @if ($renameError) aria-invalid="true" aria-describedby="name-{{ $sector->id }}-error" @endif>
                        <button class="{{ $button }}">Salvar</button>
                    </form>
                    @if ($renameError)
                        <p id="name-{{ $sector->id }}-error" class="mt-1 text-sm text-red-300">{{ $errors->first('name') }}</p>
                    @endif
                </details>

                @if ($inside->isNotEmpty())
                    <ul class="mt-3 divide-y divide-ink-800 rounded-lg border border-ink-800">
                        @foreach ($inside as $user)
                            @php $member = $accounts[$user->id]; @endphp
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" data-sector-member="{{ $member->id }}">
                                <div class="min-w-0">
                                    <p class="text-sm text-ink-200">
                                        {{ $user->name }}
                                        @if ($user->pivot->is_manager)
                                            <span class="ml-1 rounded-full border border-accent/60 px-2 py-0.5 text-[11px] text-accent">responsável</span>
                                        @endif
                                    </p>
                                    <p class="text-xs break-all text-ink-400">{{ $user->email }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    @if ($user->pivot->is_manager || $member->role === \App\Enums\CompanyRole::SectorManager)
                                        <form method="POST" action="{{ route('company.sectors.manager', [$sector, $member]) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="manager" value="{{ $user->pivot->is_manager ? 0 : 1 }}">
                                            <button class="min-h-11 rounded-md border border-ink-800 px-3 text-ink-200 hover:border-accent">
                                                {{ $user->pivot->is_manager ? 'Deixar de ser responsável' : 'Marcar como responsável' }}
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-ink-400">Para ser responsável, mude o papel em <a href="{{ route('company.accounts') }}" class="text-accent hover:underline">Contas</a></span>
                                    @endif
                                    <form method="POST" action="{{ route('company.sectors.members.remove', [$sector, $member]) }}"
                                          data-confirm="Tirar {{ $user->name }} do setor {{ $sector->name }}? A conta continua na empresa e nos outros setores dela."
                                          data-confirm-ok="Tirar do setor">
                                        @csrf
                                        @method('DELETE')
                                        <button class="min-h-11 rounded-md border border-ink-800 px-3 text-ink-200 hover:border-red-500">Tirar do setor</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 text-sm text-ink-400">Nenhuma conta neste setor ainda.</p>
                @endif

                @if ($outside->isNotEmpty())
                    <form method="POST" action="{{ route('company.sectors.members.add', $sector) }}" class="mt-3 flex gap-2">
                        @csrf
                        <label class="sr-only" for="add-{{ $sector->id }}">Conta para adicionar ao setor {{ $sector->name }}</label>
                        <select id="add-{{ $sector->id }}" name="member" required class="{{ $input }}">
                            <option value="" disabled selected>Escolha a conta…</option>
                            @foreach ($outside as $member)
                                <option value="{{ $member->id }}">{{ $member->user->name }}</option>
                            @endforeach
                        </select>
                        <button class="{{ $button }}">Adicionar ao setor</button>
                    </form>
                @elseif ($accounts->isEmpty())
                    <p class="mt-3 text-xs text-ink-400">Convide pessoas em <a href="{{ route('company.accounts') }}" class="text-accent hover:underline">Contas</a> para pô-las nos setores.</p>
                @endif

                {{-- Apagar fica no próprio cartão: a ação junto do setor, com a contagem dele (Designer, rodada 10). --}}
                @php
                    $count = $deletion[$sector->id];
                    $mine = (string) old('_apagar') === (string) $sector->id;
                @endphp
                <details class="mt-4 border-t border-ink-800 pt-3" @if ($mine) open @endif>
                    <summary class="cursor-pointer text-xs text-red-300 hover:underline">Apagar este setor</summary>
                    <form method="POST" action="{{ route('company.sectors.delete') }}" class="mt-3 space-y-3" data-confirm-tone="danger"
                          data-confirm="Apagar o setor {{ $sector->name }}? {{ \App\Services\Company\Sectors::summary($count) }} Não dá para desfazer."
                          data-confirm-ok="Apagar {{ $sector->name }}">
                        @csrf
                        @method('DELETE')
                        {{-- O setor e a contagem que o diálogo mostrou: o servidor confere de novo antes de apagar. --}}
                        <input type="hidden" name="setor" value="{{ implode('-', [$sector->id, ...array_values($count)]) }}">
                        <input type="hidden" name="_apagar" value="{{ $sector->id }}">
                        <p class="text-sm text-ink-400">
                            Apagar o setor não apaga nada do que ele monitora: os alertas de Notícias, os Radares e os monitoramentos
                            do GitHub dele ficam sem setor e passam a ser vistos pela empresa toda. As contas saem do setor e continuam
                            na empresa. Os canais de aviso do setor (Slack, Discord, Telegram e webhook) viram canais gerais, que recebem o
                            que não tem setor; se a empresa passar de {{ $generalChannels }} canais gerais, ela só conecta outro depois de remover um.
                        </p>
                        <p class="text-sm text-ink-200">{{ \App\Services\Company\Sectors::summary($count) }}</p>
                        @if ($mine)
                            @error('setor')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                        @endif
                        @include('account.partials.reauth-inline', ['suffix' => '-apagar-'.$sector->id, 'quiet' => ! $mine])
                        <button class="min-h-11 rounded-lg border border-red-900/60 px-4 text-sm text-red-300 hover:border-red-500">Apagar {{ $sector->name }}</button>
                    </form>
                </details>
            </section>
        @empty
            <p class="rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm text-ink-400">
                A empresa ainda não tem setores. Crie o primeiro acima, por exemplo com o nome de uma área da empresa.
            </p>
        @endforelse

        @if ($sectors->isNotEmpty())
            {{-- O segundo passo da confirmação (o código do app, com a 2FA), fora dos formulários dos cartões. --}}
            @include('account.partials.reauth', ['twoFactorBox' => false])
        @endif
    </div>
@endsection
