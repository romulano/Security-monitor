{{--
    Moldura dos documentos legais (/termos e /privacidade). A página filha
    define $sections (âncora => título), que vira o sumário lateral, e o corpo
    em @section('legal').
--}}
@extends('layouts.app')

@php
    $contact = config('secmon.contact_email');
    $docs = ['terms' => 'Termos de Uso', 'privacy' => 'Política de Privacidade'];
@endphp

@section('content')
    <div class="py-4">
        <header class="border-b border-ink-800 pb-6">
            <nav class="mb-5 flex flex-wrap gap-1 text-sm" aria-label="Documentos">
                @foreach ($docs as $route => $label)
                    <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
                       class="rounded-md border px-3 py-1.5 {{ request()->routeIs($route) ? 'border-ink-700 bg-ink-850 text-white' : 'border-transparent text-ink-400 hover:text-ink-200' }}">{{ $label }}</a>
                @endforeach
            </nav>

            <h1 class="text-2xl font-semibold tracking-tight text-white">@yield('heading')</h1>
            <p class="mt-2 text-sm text-ink-400">Última atualização: {{ $updated }}</p>
        </header>

        <div class="mt-8 grid gap-10 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <aside class="hidden lg:block">
                <nav class="sticky top-24 text-sm" aria-label="Sumário">
                    <p class="mb-3 text-[11px] tracking-widest text-ink-700 uppercase">Sumário</p>
                    <ol class="space-y-2">
                        @foreach ($sections as $anchor => $title)
                            <li>
                                <a href="#{{ $anchor }}" class="flex gap-2 text-ink-400 hover:text-white">
                                    <span class="w-5 shrink-0 text-right font-mono text-xs leading-5 text-ink-700">{{ $loop->iteration }}</span>
                                    <span>{{ $title }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </nav>
            </aside>

            <article class="prose-brief max-w-3xl [&_h2]:scroll-mt-24">
                @yield('legal')

                <div class="mt-10 rounded-lg border border-ink-800 bg-ink-900 p-5 text-sm">
                    <p class="font-semibold text-white">Dúvidas ou pedidos</p>
                    <p class="mt-1 mb-0 text-ink-400">
                        Escreva para <a href="mailto:{{ $contact }}">{{ $contact }}</a>. Respondemos em até 15 dias.
                    </p>
                </div>
            </article>
        </div>
    </div>
@endsection
