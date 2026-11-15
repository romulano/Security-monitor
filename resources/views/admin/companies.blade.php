@extends('layouts.admin')

@section('title', 'Empresas')

@section('content')
    @php
        $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $names = [
            'accounts' => 'Contas (com a admin)', 'domains' => 'Domínios', 'subdomains' => 'Subdomínios por domínio',
            'sectors' => 'Setores', 'github_monitors' => 'Monitoramentos do GitHub', 'alert_profiles' => 'Perfis de alerta',
            'sector_channels' => 'Canais por setor', 'general_channels' => 'Canais gerais', 'webhooks' => 'Webhooks',
            'radar_seeds' => 'Sementes do Radar', 'daily_invites' => 'Convites por dia',
        ];
        // Falhou o "salvar limites" de uma empresa: só o formulário dela mostra o que foi digitado.
        $failedLimits = (int) old('_company');
    @endphp

    <div class="mb-6">
        <h1 class="text-sm font-semibold text-white">Empresas</h1>
        <p class="text-xs text-ink-700">
            {{ $companies->total() }} {{ $companies->total() === 1 ? 'empresa' : 'empresas' }}. Cada empresa só funciona com a chave dela ligada e sem suspensão.
            A conta admin nasce junto com a empresa e não pode ser uma conta pessoal que já existe.
            Usado / limite de cada empresa (o ajustado em Ajustar limites, ou o padrão). Subdomínios e canais de setor têm limite por domínio e por setor:
            o número é o do mais cheio. Âmbar a partir de 80%; vermelho no limite.
        </p>
    </div>

    @if ($occupant)
        @php
            $kind = match (true) {
                (bool) $occupant->is_admin => 'administrador do Security Monitor',
                $occupant->companyMembership !== null => 'conta da empresa '.$occupant->companyMembership->company->name,
                default => 'conta pessoal',
            };
        @endphp
        <div class="mb-6 rounded-lg border border-amber-800/60 bg-amber-950/30 p-4 text-sm" data-occupant="{{ $occupant->id }}">
            <p class="text-amber-200">
                {{ old('admin_email') }} já é de <span class="text-white">{{ $occupant->name }}</span> ({{ $kind }}),
                e-mail {{ $occupant->hasVerifiedEmail() ? 'confirmado' : 'não confirmado' }}, conta criada em {{ $occupant->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}.
            </p>
            <p class="mt-1 text-ink-400">Uma conta existente não vira conta de empresa: use outro e-mail para a admin. Se a conta pessoal estiver abandonada, você pode removê-la antes.</p>
            @if (! $occupant->is_admin && $occupant->companyMembership === null)
                <form method="POST" action="{{ route('admin.users.destroy', $occupant) }}" class="mt-3" data-confirm-ok="Remover a conta" data-confirm-tone="danger"
                      data-confirm="Remover a conta pessoal de {{ $occupant->name }} ({{ $occupant->email }})? A conta e tudo o que está ligado a ela são apagados e a pessoa perde o acesso na hora. Não dá para desfazer.">
                    @csrf
                    @method('DELETE')
                    <button class="min-h-11 rounded-md border border-ink-800 px-3 text-xs text-ink-200 hover:text-red-400">Remover a conta pessoal</button>
                </form>
            @endif
        </div>
    @endif

    <details class="mb-6 rounded-lg border border-ink-800 bg-ink-900 p-4" @if (($errors->any() && ! $failedLimits) || old('admin_email')) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-ink-200">Nova empresa</summary>

        <form method="POST" action="{{ route('admin.companies.store') }}" class="mt-4 grid gap-3 sm:grid-cols-3">
            @csrf
            <label class="block">
                <span class="{{ $label }}">Empresa</span>
                <input name="name" required maxlength="120" value="{{ $failedLimits ? '' : old('name') }}" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="{{ $label }}">Nome de quem vai administrar</span>
                <input name="admin_name" required maxlength="255" value="{{ $failedLimits ? '' : old('admin_name') }}" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="{{ $label }}">E-mail de quem vai administrar</span>
                <input name="admin_email" type="email" required maxlength="255" value="{{ $failedLimits ? '' : old('admin_email') }}" class="{{ $input }}">
            </label>
            <div class="sm:col-span-3">
                <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Criar empresa</button>
            </div>
        </form>
    </details>

    <div class="space-y-3">
        @forelse ($companies as $company)
            @php
                $admin = $company->members->first()?->user;
                $pending = $company->members->first() && $company->members->first()->joined_at === null;
                $active = $service->isActive($company);
                // [rótulo, usado, limite, a nota embaixo do número]
                $invites = (int) $company->invites_pending;
                $usage = [
                    ['Contas', $company->accounts_used + $invites, $service->limit($company, 'accounts'),
                        $invites === 0 ? null : ($invites === 1 ? '1 convite pendente conta aqui' : "{$invites} convites pendentes contam aqui")],
                    ['Domínios', $company->domains_used, $service->limit($company, 'domains'), null],
                    ['Subdomínios', $company->subdomains_used, $service->limit($company, 'subdomains'), 'no domínio mais cheio'],
                    ['Setores', $company->sectors_used, $service->limit($company, 'sectors'), null],
                    ['Alertas de Notícias', $company->alerts_used, $service->limit($company, 'alert_profiles'), null],
                    ['GitHub', $company->github_used, $service->limit($company, 'github_monitors'), null],
                    ['Canais gerais', $company->general_channels_used, $service->limit($company, 'general_channels'), null],
                    ['Canais de setor', $company->sector_channels_used, $service->limit($company, 'sector_channels'), 'no setor mais cheio'],
                    ['Webhooks', $company->webhooks_used, $service->limit($company, 'webhooks'), null],
                ];
            @endphp
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-4" data-company="{{ $company->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-white">
                            {{ $company->name }}
                            @if ($company->isSuspended())
                                <span class="ml-1 rounded-full border border-red-800 px-2 py-0.5 text-[11px] font-normal text-red-300">suspensa desde {{ $company->suspended_at->timezone('America/Sao_Paulo')->format('d/m') }}</span>
                            @elseif ($active)
                                <span class="ml-1 rounded-full border border-emerald-800 px-2 py-0.5 text-[11px] font-normal text-emerald-300">ativa</span>
                            @else
                                <span class="ml-1 rounded-full border border-ink-700 px-2 py-0.5 text-[11px] font-normal text-ink-400">chave desligada</span>
                            @endif
                        </p>
                        @if ($admin)
                            <p class="text-xs text-ink-400">
                                Admin: <span class="text-ink-200">{{ $admin->name }}</span> · <span class="break-all">{{ $admin->email }}</span>
                                @if ($pending)
                                    <span class="text-amber-300">· ainda não entrou</span>
                                @elseif (! $admin->hasVerifiedEmail())
                                    <span class="text-amber-300">· e-mail não confirmado</span>
                                @endif
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @if ($company->enterprise_enabled_at)
                            <form method="POST" action="{{ route('admin.companies.disable', $company) }}" data-confirm-ok="Desligar" data-confirm-tone="danger"
                                  data-confirm="Desligar a chave de {{ $company->name }}? As contas da empresa deixam de usar o plano empresarial até a chave ser ligada de novo. Os dados ficam.">
                                @csrf
                                <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-200 hover:text-red-400">Desligar a chave</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.companies.enable', $company) }}" data-confirm-ok="Ligar"
                                  data-confirm="Ligar a chave de {{ $company->name }}? As contas da empresa passam a usar o plano empresarial.">
                                @csrf
                                <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-200 hover:border-accent">Ligar a chave</button>
                            </form>
                        @endif

                        @if ($pending && $active)
                            <form method="POST" action="{{ route('admin.companies.entry', $company) }}" data-confirm-ok="Reenviar"
                                  data-confirm="Mandar um link novo para {{ $admin?->email }} escolher a senha? O link vale por 7 dias. Os links enviados antes deixam de valer.">
                                @csrf
                                <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-200 hover:border-accent">Reenviar o link</button>
                            </form>
                        @endif

                        @if ($company->isSuspended())
                            <form method="POST" action="{{ route('admin.companies.resume', $company) }}" data-confirm-ok="Reativar"
                                  data-confirm="Reativar {{ $company->name }}? O acesso e os envios voltam como estavam.">
                                @csrf
                                <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-200 hover:border-accent">Reativar</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.companies.suspend', $company) }}" data-confirm-ok="Suspender" data-confirm-tone="danger"
                                  data-confirm="Suspender {{ $company->name }}? Os dados ficam intactos, mas o acesso das contas e os envios param até você reativar.">
                                @csrf
                                <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-400 hover:text-red-400">Suspender</button>
                            </form>
                        @endif
                    </div>
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($usage as [$name, $used, $limit, $hint])
                        {{-- Âmbar a partir de 80% e vermelho no limite, também em texto para o leitor de tela. --}}
                        @php($pct = (int) $limit > 0 ? $used / (int) $limit : 0)
                        <div class="rounded-md border {{ $pct >= 1 ? 'border-red-800' : ($pct >= 0.8 ? 'border-amber-800' : 'border-ink-800') }} px-3 py-2">
                            <dt class="text-[11px] tracking-widest text-ink-700 uppercase">{{ $name }}</dt>
                            <dd class="text-sm {{ $pct >= 1 ? 'text-red-300' : ($pct >= 0.8 ? 'text-amber-300' : 'text-ink-200') }}">
                                {{ (int) $used }}
                                <span class="text-ink-700">/ {{ $limit }}</span>
                                @if ($pct >= 1)
                                    <span class="sr-only">(no limite)</span>
                                @elseif ($pct >= 0.8)
                                    <span class="sr-only">(perto do limite)</span>
                                @endif
                                @if ($hint)
                                    <span class="block text-[11px] text-ink-700">{{ $hint }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <details class="mt-3" @if ($failedLimits === $company->id) open @endif>
                    <summary class="cursor-pointer text-xs text-ink-400 hover:text-ink-200">Ajustar limites</summary>

                    <form method="POST" action="{{ route('admin.companies.limits', $company) }}" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_company" value="{{ $company->id }}">
                        @foreach ($names as $key => $text)
                            <label class="block">
                                <span class="{{ $label }}">{{ $text }}</span>
                                <input name="limits[{{ $key }}]" type="number" min="0" max="100000" inputmode="numeric"
                                       placeholder="padrão {{ $defaults[$key] }}"
                                       value="{{ $failedLimits === $company->id ? old('limits.'.$key) : $company->getAttribute('limit_'.$key) }}"
                                       class="{{ $input }}">
                            </label>
                        @endforeach
                        <div class="col-span-2 flex flex-wrap items-center gap-3 sm:col-span-5">
                            <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">Salvar limites</button>
                            <span class="text-xs text-ink-700">Vazio = o padrão. 0 = nenhum. O de contas não fica abaixo das contas que a empresa já tem.</span>
                        </div>
                    </form>
                </details>
            </div>
        @empty
            <p class="rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm text-ink-400">Nenhuma empresa ainda. Crie a primeira acima, com a conta admin dela.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $companies->withQueryString()->links() }}</div>
@endsection
