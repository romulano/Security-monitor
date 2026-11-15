@extends('layouts.admin')

@section('title', 'Execuções')

@section('content')
    <h1 class="mb-3 text-sm font-semibold text-white">Histórico de execuções</h1>
    @include('admin._runs-table', ['runs' => $runs])
    <div class="mt-4">{{ $runs->links() }}</div>

    <h2 class="mt-10 mb-3 text-sm font-semibold text-white">Artigos travados na análise</h2>

    @if ($failures->isEmpty())
        <p class="rounded-lg border border-ink-800 bg-ink-900 px-4 py-6 text-center text-sm text-ink-700">
            Nada pendente — todo artigo coletado virou matéria.
        </p>
    @else
        <div class="space-y-2">
            @foreach ($failures as $article)
                <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-ink-800 bg-ink-900 p-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-ink-200">{{ $article->original_title }}</p>
                        <p class="mt-0.5 flex flex-wrap gap-x-3 text-[11px] text-ink-700">
                            <span class="font-mono">{{ $article->source_host }}</span>
                            <span>{{ $article->status }}</span>
                            <span>{{ $article->attempts }} tentativa(s)</span>
                            <span>{{ optional($article->collected_at)->diffForHumans() }}</span>
                        </p>
                        @if ($article->error)
                            <p class="mt-1 text-[11px] text-red-400">{{ Str::limit($article->error, 200) }}</p>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('admin.articles.retry', $article) }}">
                        @csrf
                        <button class="rounded-md border border-accent/40 px-2.5 py-1 text-xs text-accent hover:bg-accent/10">
                            Reprocessar
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
@endsection
