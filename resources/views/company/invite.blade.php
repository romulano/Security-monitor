@extends('layouts.app')

@section('title', 'Convite de '.$invite->company->name.' · Security Monitor')

@section('content')
    <div class="mx-auto max-w-sm py-12">
        <h1 class="mb-1 text-lg font-semibold text-white">Convite de {{ $invite->company->name }}</h1>
        <p class="mb-6 text-sm text-ink-400">
            {{ $invite->inviter?->name ?? 'Quem administra a conta' }} convidou você para a conta de <span class="text-ink-200">{{ $invite->company->name }}</span> no Security Monitor,
            como {{ $invite->role === \App\Enums\CompanyRole::SectorManager ? 'responsável do setor' : 'membro' }}.
            Digite o seu nome e escolha uma senha (mínimo de 8 caracteres). O convite vale uma vez só.
        </p>

        <form method="POST" action="{{ route('company.invite.accept', $token) }}" class="space-y-4">
            @csrf
            <input type="email" name="username" value="{{ $invite->email }}" autocomplete="username" hidden readonly>

            <div>
                <span class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">E-mail</span>
                <p class="text-sm break-all text-ink-200">{{ $invite->email }}</p>
            </div>

            <div>
                <label for="name" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Nome</label>
                <input id="name" name="name" type="text" required autofocus autocomplete="name" maxlength="120" value="{{ old('name') }}"
                       class="w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm focus:border-accent">
                @error('name')
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

            <button type="submit" class="min-h-11 w-full rounded-lg bg-accent py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                Aceitar e entrar
            </button>

            <p class="text-center text-xs text-ink-700">
                Ao criar a conta você concorda com os
                <a href="{{ route('terms') }}" class="text-ink-400 underline hover:text-ink-200">Termos de Uso</a> e a
                <a href="{{ route('privacy') }}" class="text-ink-400 underline hover:text-ink-200">Política de Privacidade</a>.
            </p>
        </form>
    </div>
@endsection
