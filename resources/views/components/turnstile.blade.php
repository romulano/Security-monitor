@props(['action'])

{{-- Captcha da Cloudflare: só aparece quando as chaves estão no .env (ver App\Support\Turnstile). --}}
@if (\App\Support\Turnstile::enabled())
    <div>
        <div class="cf-turnstile" data-sitekey="{{ \App\Support\Turnstile::siteKey() }}" data-action="{{ $action }}"
             data-theme="dark" data-language="pt-br" data-size="flexible"></div>

        @error(\App\Support\Turnstile::FIELD)
            <p class="mt-2 rounded border border-red-800/60 bg-red-950/40 px-3 py-2 text-sm text-red-300">{{ $message }}</p>
        @enderror
    </div>

    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
