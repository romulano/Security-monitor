@extends('layouts.app')

@section('title', 'Atividade da empresa · Security Monitor')

@section('content')
    @php
        $input = 'min-h-11 w-full rounded-lg border border-ink-800 bg-ink-900 px-3 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $actions = \App\Services\Company\CompanyAudit::ACTIONS;
        $audit = app(\App\Services\Company\CompanyAudit::class);
    @endphp

    <div class="mx-auto max-w-4xl space-y-6 py-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h1 class="text-lg font-semibold text-white">Atividade de {{ $company->name }}</h1>
                <p class="text-sm text-ink-400">Tudo o que mudou na empresa: convites, entradas, papéis, setores, remoções, a chave e os limites. Nada daqui pode ser apagado.</p>
            </div>
            @include('company.partials.nav')
        </div>

        <form method="GET" action="{{ route('company.activity') }}" class="grid gap-3 rounded-lg border border-ink-800 bg-ink-900 p-4 sm:grid-cols-4 sm:items-end">
            <label class="block">
                <span class="{{ $label }}">De</span>
                <input type="date" name="de" value="{{ $filters['from'] }}" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="{{ $label }}">Até</span>
                <input type="date" name="ate" value="{{ $filters['to'] }}" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="{{ $label }}">Pessoa</span>
                <select name="pessoa" class="{{ $input }}">
                    <option value="">Todas</option>
                    @foreach ($actors as $id => $name)
                        <option value="{{ $id }}" @selected($filters['actor'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $label }}">Tipo</span>
                <select name="tipo" class="{{ $input }}">
                    <option value="">Todos</option>
                    @foreach ($actions as $key => $text)
                        <option value="{{ $key }}" @selected($filters['action'] === $key)>{{ $text }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex flex-wrap gap-2 sm:col-span-4">
                <button class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Filtrar</button>
                <a href="{{ route('company.activity.csv', request()->only(['de', 'ate', 'pessoa', 'tipo'])) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-accent">Exportar CSV</a>
            </div>
        </form>

        @if ($entries->isEmpty())
            <p class="rounded-lg border border-ink-800 px-4 py-3 text-sm text-ink-400">Nada registrado com esses filtros.</p>
        @else
            <ul class="divide-y divide-ink-800 rounded-lg border border-ink-800">
                @foreach ($entries as $entry)
                    <li class="px-4 py-3" data-audit="{{ $entry->id }}">
                        <p class="text-sm break-words text-ink-200">{{ $audit->describe($entry) }}</p>
                        <p class="text-xs text-ink-400">
                            {{ $entry->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $audit->who($entry) }}
                        </p>
                    </li>
                @endforeach
            </ul>
            <div>{{ $entries->links() }}</div>
        @endif
    </div>
@endsection
