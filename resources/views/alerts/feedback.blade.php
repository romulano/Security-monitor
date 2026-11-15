@extends('layouts.app')

@section('title', 'Obrigado · Security Monitor')

@php $relevant = $match->feedback === 'relevant'; @endphp

@section('content')
    <div class="mx-auto max-w-xl py-10 text-center">
        @if ($already ?? false)
            <h1 class="text-xl font-semibold text-white">Esta resposta já estava registrada: {{ $relevant ? 'relevante' : 'não relevante' }}{{ $match->feedback_unaffected ? ', mas que não atinge vocês' : '' }}.</h1>
        @else
            <h1 class="text-xl font-semibold text-white">Pronto, marcamos como {{ $relevant ? 'relevante' : 'não relevante' }}{{ $match->feedback_unaffected ? ', mas que não atinge vocês' : '' }}.</h1>
        @endif
        <p class="mt-2 text-ink-200">“{{ $match->case?->title_pt ?: $match->case?->title }}”</p>
        <p class="mt-4 text-sm text-ink-400">
            @if ($relevant)
                Antes de cada alerta sair, a IA do Security Monitor olha as suas avaliações: notícias como esta vão continuar chegando.
            @else
                Antes de cada alerta sair, a IA do Security Monitor olha as suas avaliações: notícias parecidas com esta tendem a ficar
                seguradas. Elas continuam visíveis na aba Notícias, em “Segurados pela IA”.
            @endif
        </p>

        @if ($status ?? null)
            <p class="mx-auto mt-6 max-w-md rounded-lg border border-emerald-900 bg-emerald-950/50 px-4 py-3 text-sm text-emerald-200">{{ $status }}</p>
        @endif
        @if ($error ?? null)
            <p class="mx-auto mt-6 max-w-md rounded-lg border border-red-900 bg-red-950/50 px-4 py-3 text-sm text-red-200">{{ $error }}</p>
        @endif

        @include('alerts.partials.signed-once', ['already' => $already ?? false, 'noteOpen' => $noteOpen ?? true, 'where' => 'na aba Notícias'])

        {{-- Motivo opcional, num formulário assinado: vale sem login, uma vez, só para este alerta. O voto não troca por aqui. --}}
        @if ($noteOpen ?? true)
            <form method="POST" action="{{ $noteUrl }}" class="mx-auto mt-6 max-w-md rounded-xl border border-ink-800 bg-ink-900 p-5 text-left">
                @csrf
                @include('alerts.partials.vote-fields', ['match' => $match, 'id' => 'motivo', 'locked' => true])
                <button class="mt-4 min-h-11 w-full rounded-lg border border-ink-700 px-4 text-sm font-semibold text-white hover:border-accent">Salvar motivo</button>
            </form>
        @endif

        <a href="{{ route('alerts.news') }}"
           class="mt-6 inline-flex min-h-11 items-center rounded-lg bg-accent px-5 text-sm font-semibold text-ink-950 hover:brightness-110">Ir para Notícias</a>
    </div>
@endsection
