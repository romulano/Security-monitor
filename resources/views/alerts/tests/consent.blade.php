@extends('layouts.app')

@section('title', 'Termo aceito · '.$domain->host.' · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Services\Pentest\PentestTerms;

    $tz = 'America/Sao_Paulo';
    $hash = $consent->rendered_sha256;
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="space-y-1">
            @include('alerts.partials.crumbs', ['parents' => [['Testes de segurança', route('alerts.tests')], [$domain->host, route('alerts.tests.domains.show', ['dominio' => $domain->host])]], 'here' => 'Termo aceito'])
            <h1 class="text-xl font-semibold text-white">Termo {{ $consent->version }} · <span class="inline-block max-w-full font-mono text-lg [overflow-wrap:anywhere]">{{ $domain->host }}</span></h1>
            <p class="text-sm text-ink-400">
                Aceito em {{ $consent->accepted_at->timezone($tz)->format('d/m/Y \à\s H:i') }}{{ $consent->revoked_at ? ', revogado em '.$consent->revoked_at->timezone($tz)->format('d/m/Y \à\s H:i') : '' }}.
            </p>
            {{-- A impressão digital inteira, à vista: o `title` não chega ao celular nem ao teclado (Designer, P4c). --}}
            <p class="text-sm text-ink-400">Impressão digital: <span class="font-mono text-xs [overflow-wrap:anywhere] text-ink-200">sha256 {{ $hash }}</span></p>
        </div>

        @if ($text === null)
            <p class="rounded-xl border border-ink-800 px-5 py-4 text-sm text-ink-200">
                Este aceite é da versão {{ $consent->version }}, que não é mais a do Security Monitor. A impressão digital acima identifica o texto aceito.
            </p>
        @else
            @unless ($matches)
                <p role="alert" class="rounded-xl border border-red-900 bg-red-950/40 px-5 py-4 text-sm text-red-100">
                    O texto montado hoje não bate com a impressão digital do aceite. Fale com {{ config('secmon.contact_email') }}.
                </p>
            @endunless

            @foreach ($sections as $section)
                <section class="space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5">
                    <h2 class="text-[15px] font-semibold text-white">{{ $section['title'] }}</h2>
                    <div class="prose-brief text-sm leading-6">{{ PentestTerms::html($section['body']) }}</div>
                </section>
            @endforeach

            <section class="space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5">
                <h2 class="text-[15px] font-semibold text-white">Declarações</h2>
                <ul class="space-y-1.5 text-sm text-ink-200">
                    @foreach ($declarations as $id => $declaration)
                        <li class="flex gap-2">
                            <span aria-hidden="true" class="{{ in_array($id, $consent->declarations, true) ? 'text-emerald-300' : 'text-ink-400' }}">{{ in_array($id, $consent->declarations, true) ? '✓' : '·' }}</span>
                            <span>{{ $declaration }}<span class="sr-only">{{ in_array($id, $consent->declarations, true) ? ' (marcada)' : ' (não marcada)' }}</span></span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <a href="{{ route('alerts.tests.domains.show', ['dominio' => $domain->host]) }}" class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Voltar para a autorização</a>
    </div>
@endsection
