{{--
    "Confirme que é você" (reautenticação recente): as formas de confirmar além da senha. Recebe
    $reauth (Reauthentication::options) e, opcionais, $intro e $context (o domínio de um teste de
    segurança: o código pedido aqui diz a ação e o domínio). A senha vai no formulário da própria
    ação (reauth_password, ou current_password na troca de e-mail); cada forma daqui tem o seu
    formulário, então esta caixa fica fora do formulário da ação.
--}}
@if ($reauth['two_factor'] && ($twoFactorBox ?? true))
    {{-- Com a 2FA, o primeiro fator já foi (senha, GitHub ou código no app): falta o do app autenticador. --}}
    @php($twoFactorError = $errors->getBag('reauth')->first('reauth_two_factor_code'))
    <div class="mt-4 rounded-lg border border-accent/40 bg-ink-950/40 p-4">
        <p class="text-sm text-ink-200">
            {{ ['password' => 'Senha certa', 'github' => 'GitHub confirmado', 'code' => 'Código confirmado'][$reauth['first']] ?? 'Primeiro passo feito' }}.
            Agora falta o código do app autenticador, porque a sua conta tem a verificação em duas etapas.{{ isset($after) ? ' '.$after : '' }}
        </p>
        <form method="POST" action="{{ route('account.reauth.two-factor') }}" class="mt-3">
            @csrf
            <label for="reauth-two-factor-code" class="mb-1 block text-xs text-ink-400">Código do app (ou um código de recuperação)</label>
            <div class="flex flex-wrap gap-2">
                <input id="reauth-two-factor-code" name="reauth_two_factor_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="20" required autofocus
                       @if ($twoFactorError) aria-invalid="true" aria-describedby="reauth-two-factor-error" @endif
                       class="min-h-11 w-40 rounded-lg border {{ $twoFactorError ? 'border-red-500' : 'border-ink-800' }} bg-ink-850 px-3 font-mono text-sm tracking-widest focus:border-accent">
                <button type="submit" class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Confirmar</button>
            </div>
        </form>
        @if ($twoFactorError)
            <p id="reauth-two-factor-error" class="mt-2 text-xs text-red-400">{{ $twoFactorError }}</p>
        @endif
    </div>
@endif

@if (! $reauth['recent'] && ! $reauth['two_factor'] && ($reauth['github'] || $reauth['channels']->isNotEmpty() || $reauth['pending']))
    <div class="mt-4 rounded-lg border border-ink-800 bg-ink-950/40 p-4">
        <p class="text-sm text-ink-200">{{ $intro ?? 'Não sabe a senha? Confirme que é você de outro jeito:' }}</p>

        <div class="mt-3 flex flex-wrap gap-2">
            @if ($reauth['github'])
                <form method="POST" action="{{ route('account.reauth.github') }}">
                    @csrf
                    <button type="submit" class="flex min-h-11 items-center gap-2 rounded-lg border border-ink-800 px-3 text-sm text-ink-200 hover:border-accent">
                        @include('auth.github-icon')
                        Confirmar com o GitHub{{ $reauth['github_login'] ? ' (@'.$reauth['github_login'].')' : '' }}
                    </button>
                </form>
            @endif

            @foreach ($reauth['channels'] as $channel)
                <form method="POST" action="{{ route('account.reauth.code') }}">
                    @csrf
                    <input type="hidden" name="channel" value="{{ $channel->id }}">
                    @isset($context)
                        <input type="hidden" name="dominio" value="{{ $context }}">
                    @endisset
                    <button type="submit" class="min-h-11 rounded-lg border border-ink-800 px-3 py-1.5 text-left text-sm text-ink-200 hover:border-accent">
                        Receber um código no {{ $channel->appName() }}
                        <span class="block text-xs text-ink-400">{{ $channel->label() }}</span>
                    </button>
                </form>
            @endforeach
        </div>

        @if ($reauth['pending'])
            <form method="POST" action="{{ route('account.reauth.code.confirm') }}" class="mt-4">
                @csrf
                <label for="reauth-code" class="mb-1 block text-xs text-ink-400">
                    Código que chegou no {{ $reauth['pending']['app'] }}, válido até as {{ $reauth['pending']['until']->timezone('America/Sao_Paulo')->format('H:i') }}
                </label>
                <div class="flex gap-2">
                    <input id="reauth-code" name="reauth_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required
                           class="w-32 rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-sm tracking-widest focus:border-accent">
                    <button type="submit" class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">
                        Confirmar código
                    </button>
                </div>
            </form>
        @endif

        @error('reauth_code', 'reauth')
            <p class="mt-2 text-xs text-red-400">{{ $message }}</p>
        @enderror

        <p class="mt-3 text-xs text-ink-400">
            A confirmação vale por 10 minutos, neste navegador.
            @if ($reauth['channels']->isNotEmpty() || $reauth['pending'])
                O código vai só para uma conversa privada que já estava conectada quando você entrou, e vale uma vez.
            @endif
        </p>
    </div>
@endif
