@extends('layouts.admin')

@section('title', 'Texto completo')

@php
    use App\Services\Crawler\ManualTextIntake;

    $manual = $article->extraction === 'manual';
    $media = $article->manual_media;
    $state = $media['state'] ?? null;
    $figures = collect($media['figures'] ?? []);
    $failedImages = collect($media['images'] ?? [])->filter(fn ($i) => filled($i['error'] ?? null));
@endphp

@section('content')
    <a href="{{ route('admin.collections', ['extraction' => $manual ? 'manual' : 'summary']) }}"
       class="mb-5 inline-flex items-center gap-1.5 text-sm text-ink-700 hover:text-ink-200">
        ← Voltar às coletas
    </a>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <section>
            <h1 class="mb-1 text-sm font-semibold text-white">{{ $article->title ?: $article->original_title }}</h1>
            <p class="mb-5 text-xs text-ink-700">
                <span class="font-mono">{{ $article->source_host }}</span>
                @if ($article->published_at)
                    · pub. {{ $article->published_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
                @endif
                · <a href="{{ $article->url }}" target="_blank" rel="noopener noreferrer" class="text-accent hover:underline">abrir na fonte ↗</a>
                @if ($article->newsCase)
                    · <a href="{{ route('feed.show', $article->newsCase->uuid) }}" target="_blank" class="text-accent hover:underline">ver matéria</a>
                @endif
            </p>

            @if ($state === 'processing')
                <div class="mb-5 rounded-lg border border-sky-800/60 bg-sky-950/30 px-4 py-3 text-sm text-sky-200">
                    A IA está lendo {{ count($media['prints'] ?? []) }} print(s) e {{ count($media['images'] ?? []) }} imagem(ns) do HTML.
                    Esta página se atualiza sozinha.
                </div>
                <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">setTimeout(() => location.reload(), 15000);</script>
            @elseif ($state === 'failed')
                <div class="mb-5 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-3 text-sm text-red-300">
                    A leitura dos prints/imagens falhou: {{ $media['error'] }}. O texto do artigo não foi alterado; você pode enviar de novo.
                </div>
            @elseif ($state === 'done')
                <div class="mb-5 rounded-lg border border-emerald-800/60 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-200">
                    Prints e imagens lidos pela IA{{ isset($media['finished_at']) ? ' em '.\Carbon\Carbon::parse($media['finished_at'])->timezone('America/Sao_Paulo')->format('d/m H:i') : '' }}:
                    {{ number_format($article->content_chars, 0, ',', '.') }} caracteres de texto,
                    {{ $figures->where('part_of_post', true)->count() }} figura(s) do post e
                    {{ $figures->where('part_of_post', false)->count() }} imagem(ns) descartada(s).
                </div>
            @endif

            @if ($figures->isNotEmpty() || $failedImages->isNotEmpty())
                <h2 class="mb-2 text-[11px] font-semibold tracking-widest text-ink-700 uppercase">O que a IA decidiu sobre as imagens</h2>
                <ul class="mb-6 divide-y divide-ink-900 rounded-lg border border-ink-800 text-xs">
                    @foreach ($figures as $figure)
                        <li class="px-3 py-2">
                            <div class="mb-0.5 flex flex-wrap items-center gap-1.5">
                                <span class="font-mono text-ink-400">
                                    {{ $figure['source'] === 'print' ? 'no print '.$figure['ref'] : 'imagem '.$figure['ref'].' do HTML' }}
                                </span>
                                @if ($figure['part_of_post'])
                                    <span class="rounded bg-emerald-950/50 px-1.5 py-px text-emerald-300">faz parte do post</span>
                                @else
                                    <span class="rounded bg-ink-850 px-1.5 py-px text-ink-400">descartada</span>
                                @endif
                                @if ($figure['reason'])
                                    <span class="text-ink-700">{{ $figure['reason'] }}</span>
                                @endif
                            </div>
                            @if ($figure['part_of_post'] && $figure['description'])
                                <p class="leading-5 text-ink-200">{{ $figure['description'] }}</p>
                            @endif
                        </li>
                    @endforeach
                    @foreach ($failedImages as $image)
                        <li class="px-3 py-2 text-ink-700">
                            <span class="font-mono">{{ \Illuminate\Support\Str::limit($image['url'], 80) }}</span>
                            — não lida: {{ $image['error'] }}
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('admin.articles.text.update', $article) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                        <label for="content" class="block text-xs tracking-widest text-ink-700 uppercase">Texto ou HTML da matéria</label>
                        <div class="flex gap-3 text-xs text-ink-400">
                            @foreach (['auto' => 'detectar sozinho', 'text' => 'é texto', 'html' => 'é HTML'] as $value => $label)
                                <label class="flex items-center gap-1">
                                    <input type="radio" name="format" value="{{ $value }}" @checked(old('format', 'auto') === $value)
                                           class="border-ink-700 bg-ink-900 text-accent">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <textarea id="content" name="content" rows="16"
                              placeholder="Cole o texto da matéria, ou o HTML da página (Ctrl+U no navegador, copiar tudo). Do HTML o Security Monitor tira o texto e as imagens do corpo."
                              class="w-full rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 font-mono text-[13px] leading-6 focus:border-accent">{{ old('content', $manual && ! $media ? $article->content : '') }}</textarea>
                </div>

                <div>
                    <label for="prints" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Prints da matéria</label>
                    <input id="prints" name="prints[]" type="file" multiple accept="image/png,image/jpeg,image/webp,image/gif"
                           class="block w-full text-sm text-ink-400 file:mr-3 file:rounded-md file:border-0 file:bg-ink-850 file:px-3 file:py-1.5 file:text-ink-200 hover:file:bg-ink-800">
                    <p class="mt-1 text-[11px] text-ink-700">
                        Até {{ ManualTextIntake::MAX_PRINTS }} imagens (PNG, JPG, WEBP), 5 MB cada, na ordem de leitura.
                        A IA transcreve o texto e avalia cada imagem que aparecer: as que fazem parte do post entram na análise.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                        Salvar e refazer a análise
                    </button>
                    <span class="text-xs text-ink-700">
                        A matéria mantém o mesmo link. Com prints ou imagens, a IA lê primeiro e a análise vem em seguida.
                    </span>
                </div>
            </form>
        </section>

        <aside class="lg:sticky lg:top-4 lg:self-start">
            <h2 class="mb-2 text-[11px] font-semibold tracking-widest text-ink-700 uppercase">
                {{ $manual ? 'Texto atual (fornecido no admin)' : 'O que o crawler conseguiu' }}
            </h2>
            <p class="mb-2 text-[11px] text-ink-700">{{ number_format($article->content_chars, 0, ',', '.') }} caracteres</p>
            <div class="max-h-[32rem] overflow-y-auto rounded-lg border border-ink-800 bg-ink-900 p-3 text-xs leading-5 whitespace-pre-line text-ink-400">{{ $article->content ?: '(vazio)' }}</div>
        </aside>
    </div>
@endsection
