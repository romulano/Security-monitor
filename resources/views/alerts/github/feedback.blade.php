@extends('layouts.app')

@section('title', 'Obrigado · Security Monitor')

@php $relevant = $finding->feedback === 'relevant'; @endphp

@section('content')
    <div class="mx-auto max-w-xl py-10 text-center">
        @if ($already ?? false)
            <h1 class="text-xl font-semibold text-white">Esta resposta já estava registrada: {{ $relevant ? 'relevante' : 'não relevante' }}{{ $finding->feedback_unaffected ? ', mas já resolvido' : '' }}.</h1>
        @else
            <h1 class="text-xl font-semibold text-white">Pronto, marcamos como {{ $relevant ? 'relevante' : 'não relevante' }}{{ $finding->feedback_unaffected ? ', mas já resolvido' : '' }}.</h1>
        @endif
        <p class="mt-2 font-mono text-sm break-all text-ink-200">{{ $finding->location() }}</p>
        <p class="mt-4 text-sm text-ink-400">
            @if ($relevant)
                Antes de cada aviso do GitHub sair, a IA do Security Monitor olha as suas avaliações: achados como este vão continuar chegando.
            @else
                Antes de cada aviso do GitHub sair, a IA do Security Monitor olha as suas avaliações: achados parecidos com este tendem a ficar
                segurados. Eles continuam visíveis na página do GitHub, em “Segurados pela IA”.
            @endif
        </p>

        @if ($status ?? null)
            <p class="mx-auto mt-6 max-w-md rounded-lg border border-emerald-900 bg-emerald-950/50 px-4 py-3 text-sm text-emerald-200">{{ $status }}</p>
        @endif
        @if ($error ?? null)
            <p class="mx-auto mt-6 max-w-md rounded-lg border border-red-900 bg-red-950/50 px-4 py-3 text-sm text-red-200">{{ $error }}</p>
        @endif

        @include('alerts.partials.signed-once', ['already' => $already ?? false, 'noteOpen' => $noteOpen ?? true, 'where' => 'na página do GitHub'])

        {{-- Motivo opcional, num formulário assinado: vale sem login, uma vez, só para este achado. O voto não troca por aqui. --}}
        @if ($noteOpen ?? true)
            <form method="POST" action="{{ $noteUrl }}" class="mx-auto mt-6 max-w-md rounded-xl border border-ink-800 bg-ink-900 p-5 text-left">
                @csrf
                @include('alerts.partials.vote-fields', ['match' => $finding, 'id' => 'motivo', 'locked' => true, 'legend' => 'Este achado é',
                    'unaffectedHint' => 'Importa, mas já resolvemos: a chave foi trocada, o arquivo saiu ou não é nosso para corrigir.',
                    'placeholder' => 'Ex.: esse repositório é de um parceiro, a chave é de teste e não dá acesso a nada.',
                    'noteHint' => 'A IA lê o seu motivo para decidir os próximos avisos do GitHub. Não escreva o valor de chaves nem dados pessoais.'])
                <button class="mt-4 min-h-11 w-full rounded-lg border border-ink-700 px-4 text-sm font-semibold text-white hover:border-accent">Salvar motivo</button>
            </form>
        @endif

        <a href="{{ route('alerts.github') }}"
           class="mt-6 inline-flex min-h-11 items-center rounded-lg bg-accent px-5 text-sm font-semibold text-ink-950 hover:brightness-110">Ir para o GitHub no Security Monitor</a>
    </div>
@endsection
