{{--
    O código do app dentro do formulário da ação, quando o primeiro passo da confirmação (senha, GitHub ou
    código num app) já foi e a conta tem a 2FA. Recebe $reauth. Um clique no botão da ação e nada se perde.
    Com vários formulários na página: $suffix deixa os ids únicos, e $quiet esconde o erro (e o foco) nos que não foram enviados.
--}}
@php
    $suffix ??= '';
    $codeError = ($quiet ?? false) ? null : $errors->getBag('reauth')->first('reauth_two_factor_code');
@endphp
<div>
    <label for="reauth-two-factor-code{{ $suffix }}" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Código do app</label>
    <input id="reauth-two-factor-code{{ $suffix }}" name="reauth_two_factor_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="20" @unless (isset($help) || ($quiet ?? false)) autofocus @endunless
           aria-describedby="reauth-two-factor-help{{ $suffix }}{{ $codeError ? ' reauth-two-factor-error'.$suffix : '' }}" @if ($codeError) aria-invalid="true" @endif
           class="block min-h-11 w-40 rounded-lg border {{ $codeError ? 'border-red-500' : 'border-ink-800' }} bg-ink-850 px-3 font-mono text-sm tracking-widest focus:border-accent">
    <p id="reauth-two-factor-help{{ $suffix }}" class="mt-1 text-xs text-ink-400">
        @isset($help)
            {{ $help }}
        @else
            {{ ['password' => 'Senha certa', 'github' => 'GitHub confirmado', 'code' => 'Código confirmado'][$reauth['first']] ?? 'Primeiro passo feito' }}.
            Agora falta o código do app autenticador, porque a sua conta tem a verificação em duas etapas.
        @endisset
    </p>
    @if ($codeError)
        <p id="reauth-two-factor-error{{ $suffix }}" class="mt-1 text-xs text-red-400">{{ $codeError }}</p>
    @endif
</div>
