@extends('layouts.app')

@section('title', 'Confirme o seu e-mail · Security Monitor')

@section('content')
    <div class="mx-auto max-w-sm py-12">
        <h1 class="mb-1 text-lg font-semibold text-white">Confirme o seu e-mail</h1>
        <p class="mb-6 text-sm text-ink-400">
            Mandamos um link para <span class="text-ink-200">{{ $email }}</span>. Abra o e-mail e clique em
            <strong class="font-medium text-ink-200">Confirmar e-mail</strong>: os alertas ficam liberados na hora.
        </p>

        <ul class="mb-6 list-inside list-disc space-y-1 text-xs text-ink-700">
            <li>O link vale por uma hora.</li>
            <li>Não chegou em alguns minutos? Olhe a caixa de spam ou peça outro abaixo.</li>
            <li>O e-mail está errado? Troque em <a href="{{ route('account.show') }}" class="text-ink-400 underline hover:text-ink-200">Minha conta</a>; o link vai para o novo.</li>
        </ul>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="w-full rounded-lg bg-accent py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                Enviar outro link
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
            @csrf
            <button type="submit" class="text-xs text-ink-700 hover:text-ink-400">Sair</button>
        </form>
    </div>
@endsection
