{{--
    A confirmação dentro do formulário de uma ação sensível: a senha (e, com a 2FA na conta, o código do app
    junto) ou, se a senha já foi, só o código. Nada quando a confirmação recente vale. Recebe $reauth.
    Com vários formulários na página: $suffix deixa os ids únicos, e $quiet esconde o erro (e o foco) nos que não foram enviados.
--}}
@php($suffix ??= '')
@if ($reauth['recent'])
    <p class="text-xs text-emerald-300">Você confirmou que é você {{ $reauth['method'] }}. Vale até as {{ $reauth['until']->timezone('America/Sao_Paulo')->format('H:i') }}.</p>
@elseif ($reauth['two_factor'])
    @include('account.partials.reauth-code', ['suffix' => $suffix, 'quiet' => $quiet ?? false])
@else
    @php($passwordError = ($quiet ?? false) ? null : $errors->getBag('reauth')->first('reauth_password'))
    <div>
        <label for="reauth-password{{ $suffix }}" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Sua senha</label>
        <input id="reauth-password{{ $suffix }}" name="reauth_password" type="password" autocomplete="current-password" maxlength="255"
               aria-describedby="reauth-password-help{{ $suffix }}{{ $passwordError ? ' reauth-password-error'.$suffix : '' }}" @if ($passwordError) aria-invalid="true" autofocus @endif
               class="block min-h-11 w-full max-w-xs rounded-lg border {{ $passwordError ? 'border-red-500' : 'border-ink-800' }} bg-ink-850 px-3 text-sm focus:border-accent">
        <p id="reauth-password-help{{ $suffix }}" class="mt-1 text-xs text-ink-400">Pedimos a senha para confirmar que é você.</p>
        @if ($passwordError)
            <p id="reauth-password-error{{ $suffix }}" class="mt-1 text-xs text-red-400">{{ $passwordError }}</p>
        @endif
    </div>
    @if ($reauth['has_two_factor'])
        @include('account.partials.reauth-code', ['help' => 'Junto com a senha, porque a sua conta tem a verificação em duas etapas.', 'suffix' => $suffix, 'quiet' => $quiet ?? false])
    @endif
@endif
