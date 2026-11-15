@extends('layouts.app')

@section('title', 'Minha conta · Security Monitor')

@section('content')
    <div class="mx-auto max-w-xl space-y-6 py-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-white">Minha conta</h1>
                <p class="text-sm text-ink-700">Conta criada em {{ $user->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y') }}.</p>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-md border border-ink-800 px-3 py-1.5 text-sm text-ink-400 hover:text-red-400">
                    Sair
                </button>
            </form>
        </div>

        @if (config('secmon.enterprise.enabled') && $user->companyRole() === \App\Enums\CompanyRole::Admin)
            <a href="{{ route('company.accounts') }}" class="block rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm hover:border-accent">
                <span class="font-semibold text-white">Contas da empresa</span>
                <span class="mt-1 block text-ink-400">Convide pessoas e acompanhe quem já entrou na conta de {{ $user->company->name }}.</span>
            </a>
        @endif
        @if (config('secmon.enterprise.enabled') && $user->isCompanyAccount())
            <a href="{{ route('company.domains') }}" class="block rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm hover:border-accent">
                <span class="font-semibold text-white">Domínios da empresa</span>
                <span class="mt-1 block text-ink-400">Os domínios de {{ $user->company->name }} que você vê, com a posse comprovada de cada um.</span>
            </a>
        @endif

        @unless ($user->hasVerifiedEmail())
            <div class="rounded-lg border border-amber-800/60 bg-amber-950/30 p-4 text-sm">
                <p class="font-semibold text-amber-300">Confirme o seu e-mail para usar os alertas.</p>
                <p class="mt-1 text-ink-400">Mandamos um link para <span class="text-ink-200">{{ $user->email }}</span>. Ele vale por uma hora.</p>
                <form method="POST" action="{{ route('verification.send') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="rounded-md border border-ink-800 px-3 py-1.5 text-ink-200 hover:border-accent">Enviar outro link</button>
                </form>
            </div>
        @endunless

        @if (session('initial_password'))
            <div class="rounded-lg border border-accent/40 bg-accent/5 p-4">
                <p class="text-sm font-semibold text-white">Conta criada. Esta é a sua senha inicial:</p>
                <div class="mt-3 flex gap-2">
                    <input id="initial-password" type="text" readonly value="{{ session('initial_password') }}"
                           class="min-w-0 flex-1 rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 font-mono text-sm text-white">
                    <button type="button" data-copy="#initial-password"
                            class="shrink-0 rounded-lg border border-ink-800 px-3 py-2 text-sm text-ink-200 hover:border-accent">
                        Copiar
                    </button>
                </div>
                <p class="mt-2 text-xs text-ink-400">
                    Ela não será mostrada de novo. Anote ou troque agora por uma sua no formulário de senha abaixo.
                </p>
            </div>
        @endif

        <section class="rounded-lg border border-ink-800 bg-ink-900 p-5">
            <h2 class="mb-4 text-sm font-semibold text-white">Dados</h2>

            <form method="POST" action="{{ route('account.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <label class="block">
                    <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Nome</span>
                    <input name="name" type="text" required autocomplete="name" maxlength="120"
                           value="{{ old('name', $user->name) }}"
                           class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent">
                    @error('name', 'profile')
                        <span class="mt-1 block text-xs text-red-400">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">E-mail</span>
                    <input name="email" type="email" required autocomplete="email" maxlength="255"
                           value="{{ old('email', $user->email) }}"
                           class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent">
                    @error('email', 'profile')
                        <span class="mt-1 block text-xs text-red-400">{{ $message }}</span>
                    @enderror
                    <span class="mt-1 block text-xs text-ink-700">É com ele que você entra.</span>
                </label>

                {{-- Trocar o e-mail pede a senha atual (ou a confirmação recente): ver AccountEditor. --}}
                @if ($reauth['recent'])
                    <p class="rounded-lg border border-emerald-800/60 bg-emerald-950/30 px-3 py-2 text-xs text-emerald-200">
                        Você confirmou que é você {{ $reauth['method'] }}. Até as {{ $reauth['until']->timezone('America/Sao_Paulo')->format('H:i') }}, dá para trocar o e-mail sem digitar a senha.
                    </p>
                @elseif ($reauth['two_factor'])
                    {{-- A senha já foi; o código do app vai aqui mesmo, e o e-mail digitado continua no campo. --}}
                    @include('account.partials.reauth-code')
                @else
                    <label class="block">
                        <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Senha atual</span>
                        <input name="current_password" type="password" autocomplete="current-password" maxlength="255"
                               class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent">
                        @error('current_password', 'profile')
                            <span class="mt-1 block text-xs text-red-400">{{ $message }}</span>
                        @enderror
                        <span class="mt-1 block text-xs text-ink-400">Só para trocar o e-mail. Trocar o nome não pede a senha.</span>
                    </label>
                @endif

                <button type="submit" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                    Salvar dados
                </button>
            </form>

            @include('account.partials.reauth', [
                'twoFactorBox' => false,
                'intro' => 'Não sabe a senha? Confirme que é você de outro jeito e troque o e-mail nos 10 minutos seguintes:',
            ])
        </section>

        @if ($twoFactor !== null)
            <section class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-ink-800 bg-ink-900 p-5">
                <div>
                    <h2 class="text-sm font-semibold text-white">Verificação em duas etapas</h2>
                    <p class="mt-1 text-sm {{ $twoFactor ? 'text-emerald-300' : 'text-ink-400' }}">{{ $twoFactor ? 'Ligada, com app autenticador.' : 'Desligada.' }}</p>
                </div>
                <a href="{{ route('account.two-factor') }}" class="min-h-11 content-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-accent">
                    {{ $twoFactor ? 'Gerenciar' : 'Configurar' }}
                </a>
            </section>
        @endif

        <section class="rounded-lg border border-ink-800 bg-ink-900 p-5">
            <h2 class="mb-4 text-sm font-semibold text-white">Senha</h2>

            <form method="POST" action="{{ route('account.password') }}" class="space-y-4">
                @csrf
                @method('PUT')

                {{-- Para o gerenciador de senhas associar a nova senha à conta certa. --}}
                <input type="email" name="username" value="{{ $user->email }}" autocomplete="username" hidden readonly>

                <label class="block">
                    <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Senha atual</span>
                    <input name="current_password" type="password" required autocomplete="current-password"
                           class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent">
                    @error('current_password', 'password')
                        <span class="mt-1 block text-xs text-red-400">{{ $message }}</span>
                    @enderror
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Nova senha</span>
                        <input name="password" type="password" required minlength="8" autocomplete="new-password"
                               class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Confirmar</span>
                        <input name="password_confirmation" type="password" required minlength="8" autocomplete="new-password"
                               class="w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent">
                    </label>
                </div>

                @error('password', 'password')
                    <p class="text-xs text-red-400">{{ $message }}</p>
                @enderror

                {{-- Com a 2FA, trocar a senha pede também o código do app (AccountEditor), salvo a confirmação recente. --}}
                @if ($twoFactor && ! $reauth['recent'])
                    <label class="block">
                        <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Código do app</span>
                        <input name="reauth_two_factor_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="20"
                               class="w-40 rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-sm tracking-widest focus:border-accent">
                        @error('reauth_two_factor_code', 'reauth')
                            <span class="mt-1 block text-xs text-red-400">{{ $message }}</span>
                        @enderror
                    </label>
                @endif

                <button type="submit" class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">
                    Alterar senha
                </button>
            </form>
        </section>
    </div>
@endsection
