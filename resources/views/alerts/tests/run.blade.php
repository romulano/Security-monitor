@extends('layouts.app')

@section('title', 'Execução #'.$run->shortId().' · '.$run->domain.' · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

{{--
    Uma execução (Execucao-ao-vivo, Execucao-estados e Antes de começar). O pedaço que muda vem de
    partials/run-live e é trocado pela consulta periódica (data-run-live); se ela falha, a página fica com o
    último estado e o Parar continua valendo. O que não muda fica aqui: a trilha e como o time do site
    reconhece o teste.
--}}
@section('content')
    <div class="space-y-6">
        @include('alerts.partials.crumbs', ['parent' => ['Testes de segurança', route('alerts.tests')], 'here' => '#'.$run->shortId()])

        <div data-run-live="{{ route('alerts.tests.runs.status', ['execucao' => $run->uuid]) }}" data-poll="{{ (int) config('secmon.pentest.live_poll_seconds') }}"
             data-live="{{ $live['active'] ? '1' : '0' }}" data-login="{{ route('login') }}" data-pill="{{ $live['pill'][0] }}" class="space-y-6">
            @include('alerts.tests.partials.run-live', ['run' => $run, 'live' => $live, 'confirmed' => $confirmed])
        </div>
        {{-- O que o leitor de tela ouve da consulta periódica, uma vez por mudança: a falha e a volta (situação 9) e o
             estado novo (rodando → parando → parado). Fica fora do pedaço trocado. --}}
        <p data-run-announce class="sr-only" role="status" aria-live="polite"></p>

        @if ($reauth && ! $reauth['recent'])
            @include('account.partials.reauth', ['reauth' => $reauth, 'after' => 'Depois de confirmar, clique de novo em Retomar.', 'context' => $run->domain, 'intro' => 'Para retomar sem a senha, confirme que é você de outro jeito:'])
        @endif

        <section aria-labelledby="reconhece" class="space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="reconhece" class="text-[15px] font-semibold text-white">Como o seu time reconhece este teste</h2>
                @if ($notice)
                    <textarea id="aviso-execucao" readonly class="sr-only" tabindex="-1" aria-hidden="true">{{ $notice }}</textarea>
                    <button type="button" data-copy="#aviso-execucao" class="inline-flex min-h-11 items-center rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-ink-400">Copiar o aviso ao time</button>
                @endif
            </div>
            <dl class="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[120px_minmax(0,1fr)]">
                <dt class="text-ink-400">IP de saída</dt>
                <dd class="font-mono [overflow-wrap:anywhere] text-white">{{ $identity['ips'] }}{{ $identity['rdns'] ? ' · '.$identity['rdns'] : '' }}</dd>
                <dt class="text-ink-400">User-Agent</dt>
                <dd class="font-mono [overflow-wrap:anywhere] text-white">{{ $identity['ua'] }}</dd>
                <dt class="text-ink-400">Cabeçalho</dt>
                <dd class="font-mono [overflow-wrap:anywhere] text-white">{{ $identity['header'] }}</dd>
            </dl>
            <p class="text-xs text-ink-400">
                Portas e certificado só se identificam pelo IP. As consultas de DNS e de certificados públicos passam pelo DNS do servidor do Security Monitor, não por este IP.
            </p>
        </section>
    </div>
@endsection
