@extends('layouts.app')

@section('title', 'Criar conta · Security Monitor')

@section('content')
    <div class="mx-auto max-w-sm py-12">
        <h1 class="mb-1 text-lg font-semibold text-white">Criar conta</h1>
        <p class="mb-6 text-sm text-ink-700">
            Escolha a senha que quiser (mínimo de 8 caracteres). Depois de criar, confirme o e-mail pelo link que enviamos para usar os alertas.
        </p>

        <form method="POST" action="{{ route('register') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Nome</label>
                <input id="name" name="name" type="text" required autofocus autocomplete="name" maxlength="120" value="{{ old('name') }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm focus:border-accent">
                @error('name')
                    <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">E-mail</label>
                <input id="email" name="email" type="email" required autocomplete="email" maxlength="255" value="{{ old('email') }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm focus:border-accent">
                @error('email')
                    <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Senha</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" minlength="8" maxlength="255"
                       class="w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm focus:border-accent">
                @error('password')
                    <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Repita a senha</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" minlength="8" maxlength="255"
                       class="w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm focus:border-accent">
            </div>

            <x-turnstile action="cadastro" />

            <button type="submit"
                    class="w-full rounded-lg bg-accent py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                Criar conta
            </button>

            @if ($github)
                <div class="flex items-center gap-3 text-xs text-ink-700">
                    <span class="h-px flex-1 bg-ink-800"></span>ou<span class="h-px flex-1 bg-ink-800"></span>
                </div>

                {{-- Mesmo formulário: leva o captcha; o nome e o e-mail vêm do GitHub. --}}
                <button type="submit" formaction="{{ route('register.github') }}" formnovalidate
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-ink-800 py-2 text-sm text-ink-200 hover:border-accent">
                    @include('auth.github-icon')
                    Cadastrar com o GitHub
                </button>
                <p class="text-center text-xs text-ink-700">
                    O nome e o e-mail verificado vêm do GitHub (a conta já nasce confirmada), e uma senha é gerada para quem também quiser entrar pelo e-mail.
                </p>
            @endif

            <p class="text-center text-xs text-ink-700">
                Ao criar a conta você concorda com os
                <a href="{{ route('terms') }}" class="text-ink-400 underline hover:text-ink-200">Termos de Uso</a> e a
                <a href="{{ route('privacy') }}" class="text-ink-400 underline hover:text-ink-200">Política de Privacidade</a>.
            </p>
        </form>

        <p class="mt-6 text-center text-sm text-ink-700">
            Já tem conta?
            <a href="{{ route('login') }}" class="text-accent hover:underline">Entrar</a>
        </p>
    </div>
@endsection
