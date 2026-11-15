@extends('layouts.app')

@section('title', 'Código de verificação · Security Monitor')

@section('content')
    <div class="mx-auto max-w-sm py-12">
        <h1 class="mb-1 text-lg font-semibold text-white">Verificação em duas etapas</h1>
        <p class="mb-6 text-sm text-ink-400">
            @if ($recovery)
                Digite um dos seus códigos de recuperação (como <span class="font-mono text-ink-200">ABCDE-FGH23</span>). Cada um vale uma vez só.
            @else
                Digite o código de 6 dígitos que o app autenticador mostra agora.
            @endif
            Se passar de {{ $minutes }} minutos, é preciso entrar com a senha de novo.
        </p>

        <form method="POST" action="{{ route('login.two-factor.confirm') }}" class="space-y-4">
            @csrf

            <div>
                <label for="two-factor-code" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">{{ $recovery ? 'Código de recuperação' : 'Código do app' }}</label>
                <input id="two-factor-code" name="two_factor_code" type="text" required autofocus maxlength="20" spellcheck="false"
                       @if ($recovery) autocapitalize="characters" autocomplete="off" @else inputmode="numeric" autocomplete="one-time-code" @endif
                       @error('two_factor_code') aria-invalid="true" aria-describedby="two-factor-code-error" @enderror
                       class="min-h-11 w-full rounded-lg border @error('two_factor_code') border-red-500 @else border-ink-800 @enderror bg-ink-900 px-3 font-mono text-base tracking-widest focus:border-accent">
                @error('two_factor_code')
                    <p id="two-factor-code-error" class="mt-2 rounded border border-red-800/60 bg-red-950/40 px-3 py-2 text-sm text-red-300">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="min-h-11 w-full rounded-lg bg-accent text-sm font-semibold text-ink-950 hover:brightness-110">
                Entrar
            </button>
        </form>

        <p class="mt-6 text-sm">
            @if ($recovery)
                <a href="{{ route('login.two-factor') }}" class="text-accent hover:underline">Usar o código do app</a>
            @else
                <a href="{{ route('login.two-factor', ['recuperacao' => 1]) }}" class="text-accent hover:underline">Usar um código de recuperação</a>
            @endif
        </p>
        <p class="mt-3 text-sm text-ink-400">
            Perdeu o celular e os códigos? Só o suporte do Security Monitor consegue devolver o acesso: escreva para
            <a href="mailto:{{ config('secmon.contact_email') }}" class="text-accent hover:underline">{{ config('secmon.contact_email') }}</a>.
        </p>

        <p class="mt-6 text-center text-sm">
            <a href="{{ route($admin ? 'admin.login' : 'login') }}" class="text-ink-400 hover:text-accent">Voltar ao login</a>
        </p>
    </div>
@endsection
