@extends('layouts.admin')

@section('title', 'Alertas · Respostas escritas')

@php
    use App\Models\CustomAnswerTerm;
    $status = [
        'ready' => ['pronto', 'bg-emerald-950/50 text-emerald-300'],
        'pending' => ['traduzindo', 'bg-sky-950/50 text-sky-300'],
        'failed' => ['falhou', 'bg-red-950/50 text-red-300'],
    ];
@endphp

@section('content')
    @include('admin.alerts._nav', ['subtitle' => 'O que os clientes escreveram em “Não achou? Escreva aqui” e os termos de busca, em inglês, que cada resposta virou. Corrigir aqui vale para todos que escreveram a mesma coisa.'])

    @if ($answers->isEmpty())
        <p class="rounded-lg border border-ink-800 px-4 py-10 text-center text-sm text-ink-400">Nenhum cliente escreveu uma resposta própria ainda.</p>
    @else
        <ul class="divide-y divide-ink-900 rounded-lg border border-ink-800">
            @foreach ($answers as $answer)
                @php
                    $who = $usage[$answer->kind.'|'.$answer->normalized] ?? [];
                    $values = $answer->kind === 'region' ? ($answer->codes ?? []) : ($answer->terms ?? []);
                @endphp
                <li class="space-y-2 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-semibold text-white">{{ $answer->label }}</span>
                        <span class="rounded border border-ink-700 px-1.5 py-px text-[11px] text-ink-400">{{ CustomAnswerTerm::KINDS[$answer->kind] ?? $answer->kind }}</span>
                        <span class="rounded px-1.5 py-px text-[11px] {{ $status[$answer->status][1] ?? '' }}">{{ $status[$answer->status][0] ?? $answer->status }}</span>
                        @if ($answer->edited_by_admin)
                            <span class="text-[11px] text-amber-200">corrigido no admin</span>
                        @endif
                        <span class="text-xs text-ink-400">
                            {{ count($who) }} cliente(s){{ $who ? ': '.implode(', ', array_filter($who)) : '' }}
                        </span>
                    </div>

                    @if ($answer->error)
                        <p class="text-xs text-red-400">{{ $answer->error }}</p>
                    @endif

                    <form method="POST" action="{{ route('admin.alerts.custom.update', $answer) }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        @method('PUT')
                        <label for="custom-{{ $answer->id }}" class="sr-only">Termos de {{ $answer->label }}</label>
                        <input id="custom-{{ $answer->id }}" name="values" type="text" value="{{ implode(', ', $values) }}"
                               placeholder="{{ $answer->kind === 'region' ? 'códigos ISO dos países, ex.: AO, MZ, PT' : 'termos em inglês separados por vírgula, ex.: adult entertainment, adult content' }}"
                               class="min-w-72 flex-1 rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-[13px]">
                        <button class="rounded-lg border border-ink-700 px-3 py-2 text-sm text-ink-200 hover:border-ink-400">Salvar</button>
                    </form>
                    <form method="POST" action="{{ route('admin.alerts.custom.regenerate', $answer) }}">
                        @csrf
                        <button class="text-xs text-ink-400 hover:text-ink-200">gerar de novo com a IA</button>
                    </form>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs text-ink-400">
            A própria resposta também vale como termo. Termos terminados em * valem como prefixo (ex.: <span class="font-mono">gambl*</span>).
        </p>
    @endif
@endsection
