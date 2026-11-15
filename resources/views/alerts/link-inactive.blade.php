{{-- Link de uma mensagem (/a/, /r/, /g/) que já não vale: empresa suspensa, conta desativada ou sem visão do item (E2e). Não diz qual, para não contar a terceiros o que houve com a empresa. --}}
@extends('layouts.app')

@section('title', 'Link inativo · Security Monitor')

@section('content')
    <div class="mx-auto max-w-xl py-10 text-center">
        <h1 class="text-xl font-semibold text-white">Este link não está mais ativo.</h1>
        <p class="mt-3 text-sm text-ink-300">Nada foi registrado. Se você ainda usa o Security Monitor, entre na sua conta e avalie o alerta pela tela de Alertas.</p>
        <p class="mt-6"><a href="{{ route('login') }}" class="text-sm font-medium text-violet-300 hover:text-violet-200">Entrar no Security Monitor</a></p>
    </div>
@endsection
