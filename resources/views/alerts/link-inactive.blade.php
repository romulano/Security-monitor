{{-- Link de uma mensagem (/a/, /r/, /g/) que já não vale: a conta deixou de ver o item. --}}
@extends('layouts.app')

@section('title', 'Link inativo · Security Monitor')

@section('content')
    <div class="mx-auto max-w-xl py-10 text-center">
        <h1 class="text-xl font-semibold text-white">Este link não está mais ativo.</h1>
        <p class="mt-3 text-sm text-ink-300">Nada foi registrado. Se você ainda usa o Security Monitor, entre na sua conta e avalie o alerta pela tela de Alertas.</p>
    </div>
@endsection
