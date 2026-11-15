{{-- A senha da reautenticação, um campo só por tela da 2FA, e só quando a confirmação recente não vale. --}}
@unless ($reauth['recent'])
    @php($passwordError = $errors->getBag('reauth')->first('reauth_password'))
    @php($codeError = $errors->getBag('reauth')->first('reauth_two_factor_code'))
    @unless ($reauth['two_factor'])
    <div>
        <label for="reauth-password" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Sua senha</label>
        <input id="reauth-password" name="reauth_password" type="password" autocomplete="current-password" maxlength="255"
               aria-describedby="reauth-password-help{{ $passwordError ? ' reauth-password-error' : '' }}" @if ($passwordError) aria-invalid="true" autofocus @endif
               class="block min-h-11 w-full max-w-xs rounded-lg border {{ $passwordError ? 'border-red-500' : 'border-ink-800' }} bg-ink-850 px-3 text-sm focus:border-accent">
        <p id="reauth-password-help" class="mt-1 text-xs text-ink-400">Pedimos a senha para confirmar que é você.</p>
        @if ($passwordError)
            <p id="reauth-password-error" class="mt-2 text-xs text-red-400">{{ $passwordError }}</p>
        @endif
    </div>
    @endunless
    {{-- Com a 2FA ligada, o código do app vai junto (o primeiro fator sozinho não basta). --}}
    @if ($status['state'] === 'on')
        <div>
            <label for="reauth-two-factor-code" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Código do app</label>
            <input id="reauth-two-factor-code" name="reauth_two_factor_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="20"
                   @if ($codeError) aria-invalid="true" aria-describedby="reauth-two-factor-error" autofocus @endif
                   class="block min-h-11 w-40 rounded-lg border {{ $codeError ? 'border-red-500' : 'border-ink-800' }} bg-ink-850 px-3 font-mono text-sm tracking-widest focus:border-accent">
            @if ($codeError)
                <p id="reauth-two-factor-error" class="mt-2 text-xs text-red-400">{{ $codeError }}</p>
            @endif
        </div>
    @endif
@endunless
