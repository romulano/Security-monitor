@extends('layouts.app')

@section('title', 'Obrigado · Security Monitor')

@php
    $labels = \App\Services\Lookalike\LookalikeVerdicts::LABELS;
@endphp

@section('content')
    <div class="mx-auto max-w-xl py-10 text-center">
        @if ($already ?? false)
            <h1 class="text-xl font-semibold text-white">A resposta sobre {{ $lookalike->displayHost() }} já estava registrada: {{ $labels[$lookalike->verdict] ?? 'avaliado' }}.</h1>
        @else
            <h1 class="text-xl font-semibold text-white">Pronto, marcamos {{ $lookalike->displayHost() }} como {{ $labels[$lookalike->verdict] ?? 'avaliado' }}.</h1>
        @endif
        <p class="mt-4 text-sm text-ink-400">
            @if ($lookalike->verdict === 'threat')
                Use o kit de denúncia para pedir a derrubada. A IA do Security Monitor passa a tratar domínios parecidos com este como golpe.
            @elseif ($lookalike->verdict === 'ours')
                Ele sai da lista de ameaças e não gera mais aviso. A IA do Security Monitor aprende com a sua resposta.
            @else
                Ele sai da lista de ameaças; se subir de nível, volta a aparecer. A IA do Security Monitor aprende com a sua resposta.
            @endif
        </p>

        @if ($status ?? null)
            <p class="mx-auto mt-6 max-w-md rounded-lg border border-emerald-900 bg-emerald-950/50 px-4 py-3 text-sm text-emerald-200">{{ $status }}</p>
        @endif
        @if ($error ?? null)
            <p class="mx-auto mt-6 max-w-md rounded-lg border border-red-900 bg-red-950/50 px-4 py-3 text-sm text-red-200">{{ $error }}</p>
        @endif

        @include('alerts.partials.signed-once', ['already' => $already ?? false, 'noteOpen' => $noteOpen ?? true, 'where' => 'no Radar'])

        {{-- Motivo opcional, num formulário assinado: vale sem login, uma vez, só para este domínio. A resposta não troca por aqui. --}}
        @if ($noteOpen ?? true)
            <form method="POST" action="{{ $noteUrl }}" class="mx-auto mt-6 max-w-md rounded-xl border border-ink-800 bg-ink-900 p-5 text-left">
                @csrf
                @include('alerts.lookalikes.partials.verdict-fields', ['lookalike' => $lookalike, 'id' => 'motivo', 'locked' => true])
                <button class="mt-4 min-h-11 w-full rounded-lg border border-ink-700 px-4 text-sm font-semibold text-white hover:border-accent">Salvar motivo</button>
            </form>
        @endif

        <div class="mt-6 flex flex-wrap justify-center gap-2">
            @if ($lookalike->verdict === 'threat')
                <a href="{{ route('alerts.lookalikes.kit', $lookalike) }}" class="inline-flex min-h-11 items-center rounded-lg bg-red-400 px-5 text-sm font-semibold text-red-950 hover:brightness-110">Abrir o kit de denúncia</a>
            @endif
            <a href="{{ route('alerts.lookalikes.show', $lookalike) }}" class="inline-flex min-h-11 items-center rounded-lg bg-accent px-5 text-sm font-semibold text-ink-950 hover:brightness-110">Ver no Radar</a>
        </div>
    </div>
@endsection
