@extends('layouts.app')

@section('title', ($admin ? 'Admin' : 'Entrar').' · Security Monitor')

@section('content')
    <div class="mx-auto max-w-sm py-12">
        @if ($admin)
            <h1 class="mb-1 text-lg font-semibold text-white">Área administrativa</h1>
            <p class="mb-6 text-sm text-ink-700">Controle dos bots, fontes e do driver de IA.</p>
        @else
            <h1 class="mb-1 text-lg font-semibold text-white">Entrar</h1>
            <p class="mb-6 text-sm text-ink-700">Acesse a sua conta do Security Monitor.</p>
        @endif

        <form method="POST" action="{{ route($admin ? 'admin.login' : 'login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">E-mail</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username" value="{{ old('email') }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm focus:border-accent">
            </div>

            <div>
                <label for="password" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Senha</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm focus:border-accent">
            </div>

            <label class="flex items-center gap-2 text-sm text-ink-400">
                <input type="checkbox" name="remember" value="1" class="rounded border-ink-700 bg-ink-900 text-accent">
                Continuar conectado
            </label>

            @error('email')
                <p class="rounded border border-red-800/60 bg-red-950/40 px-3 py-2 text-sm text-red-300">{{ $message }}</p>
            @enderror

            @error('password')
                <p class="rounded border border-red-800/60 bg-red-950/40 px-3 py-2 text-sm text-red-300">{{ $message }}</p>
            @enderror

            <x-turnstile :action="$admin ? 'admin-login' : 'login'" />

            <button type="submit"
                    class="w-full rounded-lg bg-accent py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                Entrar
            </button>
        </form>

        @if ($github)
            <div class="my-5 flex items-center gap-3 text-xs text-ink-700">
                <span class="h-px flex-1 bg-ink-800"></span>ou<span class="h-px flex-1 bg-ink-800"></span>
            </div>

            <a href="{{ route('login.github') }}"
               class="flex w-full items-center justify-center gap-2 rounded-lg border border-ink-800 py-2 text-sm text-ink-200 hover:border-accent">
                @include('auth.github-icon')
                Entrar com o GitHub
            </a>
        @endif

        @unless ($admin)
            <p class="mt-6 text-center text-sm text-ink-700">
                Ainda não tem conta?
                <a href="{{ route('register') }}" class="text-accent hover:underline">Criar conta</a>
            </p>
        @endunless
    </div>
@endsection
