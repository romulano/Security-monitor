@extends('layouts.app')

@section('title', 'Contas da empresa · Security Monitor')

@section('content')
    @php
        $input = 'w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $day = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m/Y');
        $full = $used >= $limit;
    @endphp

    <div class="mx-auto max-w-3xl space-y-6 py-6">
        <div>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-lg font-semibold text-white">Contas de {{ $company->name }}</h1>
                @include('company.partials.nav')
            </div>
            <p class="text-sm text-ink-400">
                {{ $used }} de {{ $limit }} contas em uso, contando os convites pendentes. A conta de empresa nasce pelo convite:
                um e-mail que já tem conta no Security Monitor não entra, e nada de uma conta pessoal migra.
            </p>
        </div>

        <form method="POST" action="{{ route('company.invites.store') }}" class="grid gap-3 rounded-lg border border-ink-800 bg-ink-900 p-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end">
            @csrf
            <label class="block">
                <span class="{{ $label }}">E-mail</span>
                <input name="email" type="email" required maxlength="255" value="{{ old('email') }}" class="{{ $input }}" @disabled($full)>
            </label>
            <label class="block">
                <span class="{{ $label }}">Papel</span>
                <select name="role" class="{{ $input }}" @disabled($full)>
                    <option value="member" @selected(old('role', 'member') === 'member')>Membro</option>
                    <option value="sector_manager" @selected(old('role') === 'sector_manager')>Responsável do setor</option>
                </select>
            </label>
            <button class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110 disabled:opacity-50" @disabled($full)>Convidar</button>
            @if ($sectors->isNotEmpty())
                <fieldset class="sm:col-span-3">
                    <legend class="{{ $label }}">Setores (opcional)</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($sectors as $id => $name)
                            <label class="inline-flex min-h-11 min-w-0 items-center gap-2 rounded-lg border border-ink-800 px-3 text-sm text-ink-200 has-[:checked]:border-accent/60">
                                <input type="checkbox" name="sectors[]" value="{{ $id }}" class="size-4 accent-accent" @checked(in_array($id, (array) old('sectors', []))) @disabled($full)>
                                <span class="break-words">{{ $name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-ink-400">A conta entra nesses setores ao aceitar. Como responsável do setor, já responde por eles.</p>
                </fieldset>
            @endif
            <p class="text-xs text-ink-400 sm:col-span-3">
                @if ($full)
                    Todas as vagas estão em uso. Para convidar outra pessoa, remova uma conta ou cancele um convite pendente. Precisa de mais? Escreva para contato@example.com.
                @else
                    O convite vale por 7 dias e uma vez só. Ele ocupa uma vaga até ser aceito, cancelado ou vencer.
                @endif
            </p>
        </form>

        @php
            $active = $members->reject->isDeactivated();
            $resettable = $members->filter(fn ($member) => in_array($member->user_id, $twoFactorIds, true) && $member->user_id !== auth()->id());
            $mine = in_array(auth()->id(), $twoFactorIds, true);
        @endphp
        <section class="rounded-lg border border-ink-800 bg-ink-900 p-4">
            <h2 class="text-sm font-semibold text-white">Verificação em duas etapas</h2>
            <p class="mt-1 text-sm text-ink-400">
                @if ($company->require_2fa)
                    Exigida: a conta sem a verificação em duas etapas só usa o Security Monitor depois de configurar a dela.
                @else
                    Opcional: cada conta decide. Exigir faz quem ainda não ligou configurar antes de qualquer outra coisa.
                @endif
                {{ $active->whereIn('user_id', $twoFactorIds)->count() }} de {{ $active->count() }} contas já ligaram.
            </p>

            <form method="POST" action="{{ route('company.two-factor.update') }}" class="mt-4 space-y-3">
                @csrf
                @include('account.partials.reauth-inline')
                @if ($company->require_2fa)
                    <button name="required" value="0" class="min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-accent"
                            data-confirm="Deixar a verificação em duas etapas opcional? Quem já ligou continua com ela; quem não ligou volta a entrar só com a senha.">
                        Deixar opcional
                    </button>
                @elseif (! $mine)
                    {{-- Exigir (e redefinir) pede a 2FA da própria admin: o botão diz por quê antes de enviar. --}}
                    <button type="button" disabled class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 opacity-50">Exigir de todas as contas</button>
                    <p class="text-xs text-ink-400">
                        Ligue antes a sua verificação em duas etapas:
                        <a href="{{ route('account.two-factor') }}" class="text-accent hover:underline">Minha conta → Verificação em duas etapas</a>.
                    </p>
                @else
                    <button name="required" value="1" class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110"
                            data-confirm="Exigir a verificação em duas etapas de todas as contas? Quem ainda não ligou recebe um e-mail e, no próximo acesso, só consegue configurar a dela.">
                        Exigir de todas as contas
                    </button>
                @endif

                @if ($mine && $resettable->isNotEmpty())
                    <div class="border-t border-ink-800 pt-4">
                        <h3 class="text-sm font-semibold text-white">Redefinir a 2FA de uma conta</h3>
                        <p class="mt-1 mb-3 text-xs text-ink-400">
                            Use quando alguém perdeu o celular e os códigos de recuperação. Antes, confirme por outro canal (telefone, chat
                            da empresa) que o pedido é mesmo da pessoa.
                        </p>
                        <label for="reset-member" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Conta</label>
                        <div class="flex flex-wrap gap-2">
                            {{-- A pergunta e o botão da caixa de confirmação vêm da conta escolhida (select[data-confirm-choice]). --}}
                            <select id="reset-member" name="member" data-confirm-choice class="min-h-11 min-w-0 flex-1 rounded-lg border border-ink-800 bg-ink-850 px-3 text-sm focus:border-accent">
                                @foreach ($resettable as $member)
                                    <option value="{{ $member->user_id }}" data-confirm-ok="Redefinir a de {{ $member->user->name }}"
                                            data-confirm="Redefinir a verificação em duas etapas de {{ $member->user->name }} ({{ $member->user->email }})? O app e os códigos de recuperação dela deixam de valer, e ela sai dos aparelhos conectados. Ela recebe um e-mail e configura de novo no próximo acesso.">{{ $member->user->name }}</option>
                                @endforeach
                            </select>
                            <button formaction="{{ route('company.two-factor.reset') }}" class="min-h-11 rounded-lg border border-red-900/60 px-4 text-sm text-red-300 hover:border-red-500">
                                Redefinir a 2FA
                            </button>
                        </div>
                    </div>
                @endif
            </form>
            @include('account.partials.reauth', ['twoFactorBox' => false])
        </section>

        <section>
            <h2 class="mb-1 text-sm font-semibold text-white">Contas</h2>
            <p class="mb-2 text-xs text-ink-400">Membro: vê os alertas dos seus setores e vota neles, sem configurar nada. Responsável do setor: também cuida dos domínios, monitoramentos e canais do setor.</p>
            <ul class="divide-y divide-ink-800 rounded-lg border border-ink-800">
                @foreach ($members as $member)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" data-member="{{ $member->user_id }}">
                        <div class="min-w-0">
                            <p class="text-sm text-ink-200">{{ $member->user->name }}@if ($member->user_id === auth()->id()) <span class="text-[11px] text-ink-400">(você)</span>@endif</p>
                            <p class="text-xs break-all text-ink-400">{{ $member->user->email }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            @if ($member->role === \App\Enums\CompanyRole::Admin || $member->isDeactivated())
                                <span class="rounded-full border border-ink-700 px-2 py-0.5 text-ink-400">{{ $member->role->label() }}</span>
                            @else
                                <form method="POST" action="{{ route('company.members.role', $member) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <label class="sr-only" for="role-{{ $member->id }}">Papel de {{ $member->user->name }}</label>
                                    <select id="role-{{ $member->id }}" name="role" class="min-h-11 rounded-md border border-ink-800 bg-ink-900 px-2 text-xs text-ink-200">
                                        <option value="member" @selected($member->role === \App\Enums\CompanyRole::Member)>Membro</option>
                                        <option value="sector_manager" @selected($member->role === \App\Enums\CompanyRole::SectorManager)>Responsável do setor</option>
                                    </select>
                                    <button class="min-h-11 rounded-md border border-ink-800 px-3 text-ink-200 hover:border-accent">Salvar</button>
                                </form>
                            @endif
                            @if (in_array($member->user_id, $twoFactorIds, true))
                                <span class="text-emerald-300">2FA ligada</span>
                            @elseif ($company->require_2fa)
                                <span class="text-amber-300">sem 2FA: vai configurar no próximo acesso</span>
                            @else
                                <span class="text-ink-400">sem 2FA</span>
                            @endif
                            @if ($member->isDeactivated())
                                <span class="text-red-300">removida em {{ $day($member->deactivated_at) }} · será apagada em {{ $day($member->deactivated_at->copy()->addDays(\App\Services\Company\CompanyRemoval::DAYS)) }}, se não for reativada</span>
                                <form method="POST" action="{{ route('company.members.reactivate', $member) }}" class="flex w-full flex-wrap items-end gap-2" data-confirm-ok="Reativar {{ $member->user->name }}"
                                      data-confirm="Reativar a conta de {{ $member->user->name }}? Ela volta a entrar com a senha de antes e ocupa uma vaga de novo.">
                                    @csrf
                                    <input type="hidden" name="_member" value="{{ $member->id }}">
                                    @unless ($reauth['recent'])
                                        <label class="block">
                                            <span class="{{ $label }}">Sua senha</span>
                                            <input type="password" name="reauth_password" autocomplete="current-password" class="{{ $input }}"
                                                   @if (old('_member') == $member->id && $errors->reauth->has('reauth_password')) aria-invalid="true" aria-describedby="reactivate-password-error-{{ $member->id }}" @endif>
                                        </label>
                                    @endunless
                                    <button class="min-h-11 rounded-md border border-ink-800 px-3 text-ink-200 hover:border-accent">Reativar {{ $member->user->name }}</button>
                                    @if (old('_member') == $member->id)
                                        @error('reauth_password', 'reauth')<p id="reactivate-password-error-{{ $member->id }}" class="w-full text-red-300">{{ $message }}</p>@enderror
                                    @endif
                                </form>
                            @elseif ($member->joined_at)
                                <span class="text-ink-400">entrou em {{ $day($member->joined_at) }}</span>
                            @else
                                <span class="text-amber-300">ainda não entrou</span>
                            @endif
                        </div>
                        @if ($member->role !== \App\Enums\CompanyRole::Admin && ! $member->isDeactivated())
                            <details class="w-full text-xs" @if (old('_member') == $member->id) open @endif>
                                <summary class="cursor-pointer text-ink-400 hover:text-red-400">Remover a conta</summary>
                                <form method="POST" action="{{ route('company.members.deactivate', $member) }}" class="mt-2 flex flex-wrap items-end gap-2" data-confirm-ok="Remover {{ $member->user->name }}" data-confirm-tone="danger"
                                      data-confirm="Remover a conta de {{ $member->user->name }}? Ela perde o acesso na hora e é apagada em 30 dias, se você não a reativar antes. O que ela criou fica com a empresa.">
                                    @csrf
                                    <input type="hidden" name="_member" value="{{ $member->id }}">
                                    @unless ($reauth['recent'])
                                        <label class="block">
                                            <span class="{{ $label }}">Sua senha</span>
                                            <input type="password" name="reauth_password" autocomplete="current-password" class="{{ $input }}"
                                                   @if (old('_member') == $member->id && $errors->reauth->has('reauth_password')) aria-invalid="true" aria-describedby="remove-password-error-{{ $member->id }}" @endif>
                                        </label>
                                    @endunless
                                    <button class="min-h-11 rounded-md border border-red-900/60 px-3 text-red-300 hover:border-red-700">Remover {{ $member->user->name }}</button>
                                    @if (old('_member') == $member->id)
                                        @error('reauth_password', 'reauth')<p id="remove-password-error-{{ $member->id }}" class="w-full text-red-300">{{ $message }}</p>@enderror
                                    @endif
                                </form>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        @php $eligible = $members->filter(fn ($m) => $m->role !== \App\Enums\CompanyRole::Admin && ! $m->isDeactivated() && $m->joined_at); @endphp
        @if ($eligible->isNotEmpty())
            <section class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <h2 class="text-sm font-semibold text-white">Passar a administração</h2>
                <p class="mb-3 text-sm text-ink-400">Quem você escolher passa a administrar a empresa: convida pessoas, muda papéis e cuida das configurações. A sua conta continua na empresa como membro. Só aparecem contas que já entraram. Para confirmar, pedimos a sua senha.</p>
                {{-- A pergunta e o botão da caixa de confirmação vêm da opção escolhida (select[data-confirm-choice]). --}}
                <form method="POST" action="{{ route('company.admin.transfer') }}" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" data-confirm-tone="danger"
                      data-confirm="Passar a administração de {{ $company->name }}? Você deixa de convidar pessoas, mudar papéis e cuidar da empresa.">
                    @csrf
                    <label class="block">
                        <span class="{{ $label }}">Quem vai administrar</span>
                        <select name="member" class="{{ $input }}" data-confirm-choice>
                            @foreach ($eligible as $member)
                                <option value="{{ $member->id }}" @selected((int) old('member') === $member->id)
                                        data-confirm-ok="Passar para {{ $member->user->name }}"
                                        data-confirm="Passar a administração de {{ $company->name }} para {{ $member->user->name }} ({{ $member->user->email }})? Você deixa de convidar pessoas, mudar papéis e cuidar da empresa, e só {{ $member->user->name }} poderá devolver a administração a você.">{{ $member->user->name }} · {{ $member->role->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($reauth['recent'])
                        <p class="text-sm text-emerald-300">Confirmado {{ $reauth['method'] }}; vale até as {{ $reauth['until']->timezone('America/Sao_Paulo')->format('H:i') }}.</p>
                    @else
                        <label class="block">
                            <span class="{{ $label }}">Sua senha</span>
                            <input type="password" name="reauth_password" autocomplete="current-password" class="{{ $input }}"
                                   @if (! old('_member') && $errors->reauth->has('reauth_password')) aria-invalid="true" aria-describedby="transfer-password-error" @endif>
                        </label>
                    @endif
                    <button class="min-h-11 rounded-lg border border-red-900/60 px-4 text-sm text-red-300 hover:border-red-700">Passar a administração</button>
                    @unless (old('_member'))
                        @error('reauth_password', 'reauth')<p id="transfer-password-error" class="text-sm text-red-300 sm:col-span-3">{{ $message }}</p>@enderror
                    @endunless
                    @error('member')<p class="text-sm text-red-300 sm:col-span-3">{{ $message }}</p>@enderror
                </form>
                @include('account.partials.reauth', ['reauth' => $reauth])
            </section>
        @endif

        <section>
            <h2 class="mb-2 text-sm font-semibold text-white">Convites</h2>
            @forelse ($invites as $invite)
                @if ($loop->first)
                    <ul class="divide-y divide-ink-800 rounded-lg border border-ink-800">
                @endif
                <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" data-invite="{{ $invite->id }}">
                    <div class="min-w-0">
                        <p class="text-sm break-all text-ink-200">{{ $invite->email }}</p>
                        <p class="text-xs text-ink-400">
                            {{ $invite->role->label() }} ·
                            @if ($names = collect($invite->sector_ids)->map(fn ($id) => $sectors[$id] ?? null)->filter()->implode(', '))
                                {{ $names }} ·
                            @endif
                            @if ($invite->isPending())
                                vale até {{ $day($invite->expires_at) }}
                            @else
                                <span class="text-amber-300">venceu em {{ $day($invite->expires_at) }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('company.invites.resend', $invite) }}" data-confirm-ok="Reenviar"
                              data-confirm="Mandar um convite novo para {{ $invite->email }}? Ele vale por 7 dias, e o link anterior deixa de valer.">
                            @csrf
                            <button class="min-h-11 rounded-md border border-ink-800 px-3 text-xs text-ink-200 hover:border-accent">Reenviar</button>
                        </form>
                        <form method="POST" action="{{ route('company.invites.cancel', $invite) }}" data-confirm-ok="Cancelar convite" data-confirm-tone="danger"
                              data-confirm="Cancelar o convite para {{ $invite->email }}? O link deixa de valer e a vaga fica livre.">
                            @csrf
                            @method('DELETE')
                            <button class="min-h-11 rounded-md border border-ink-800 px-3 text-xs text-ink-400 hover:text-red-400">Cancelar</button>
                        </form>
                    </div>
                </li>
                @if ($loop->last)
                    </ul>
                @endif
            @empty
                <p class="rounded-lg border border-ink-800 px-4 py-3 text-sm text-ink-400">Nenhum convite aberto.</p>
            @endforelse
        </section>
    </div>
@endsection
