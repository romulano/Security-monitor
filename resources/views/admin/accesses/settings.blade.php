@extends('layouts.admin')

@section('title', 'Acessos · Ajustes')

@section('content')
    @php
        $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $box = 'mb-6 max-w-3xl rounded-lg border border-ink-800 bg-ink-900 p-4';
        $title = 'mb-2 text-xs font-semibold tracking-widest text-ink-700 uppercase';
    @endphp

    @include('admin.accesses._tabs', ['route' => 'admin.accesses.settings'])

    {{-- A chave da monitoria (PageViewRecorder): desligar só para de gravar; o que já existe some no prazo de 6 meses. --}}
    <section class="{{ $box }}">
        <h2 class="{{ $title }}">Monitoria</h2>
        <p class="mb-3 text-sm {{ $enabled ? 'text-emerald-300' : 'text-ink-400' }}">{{ $enabled ? '● Gravando' : '○ Desligada' }}</p>
        <p class="mb-3 text-xs leading-relaxed text-ink-700">
            {{ $enabled
                ? 'Cada página aberta no site é gravada (IP, país, navegador, conta, página e tempo) e aparece nas outras abas. Desligada, nada novo é gravado; o que já existe some no prazo de 6 meses.'
                : 'Nada novo é gravado; o que já existe some no prazo de 6 meses. Ligada, as próximas páginas abertas no site aparecem nas outras abas.' }}
        </p>
        <form method="POST" action="{{ route('admin.accesses.toggle') }}"
              @if ($enabled) data-confirm="Desligar a monitoria? Nada novo é gravado até ligar de novo; o que já existe fica até o prazo de 6 meses." data-confirm-ok="Desligar" @endif>
            @csrf
            <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">
                {{ $enabled ? 'Desligar' : 'Ligar' }} a monitoria
            </button>
        </form>
    </section>

    {{-- Os nossos: a conferência do deploy, os scripts e quem cuida do site (OwnAddresses). Escondidos por padrão, nunca apagados. --}}
    <section class="{{ $box }}">
        <h2 class="{{ $title }}">IPs nossos</h2>
        <p class="mb-3 text-xs leading-relaxed text-ink-700">
            Contam como nossos, com o selo "nosso", a rede interna e o loopback ({{ implode(', ', $own['internal']) }}),
            os endereços do servidor ({{ $own['server'] ? implode(', ', $own['server']) : 'nenhum em SECMON_OWN_ADDRESSES' }}) e a lista abaixo.
            A comparação é feita pelo IP que cada acesso já tem: mudou a lista, os acessos de antes acompanham.
            Na ficha de um IP, "Contar como nosso" põe o IP aqui.
        </p>
        <form method="POST" action="{{ route('admin.accesses.own') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            @method('PUT')
            <label class="block min-w-64 flex-1">
                <span class="{{ $label }}">Um IP ou faixa (CIDR) por linha</span>
                <textarea name="enderecos" rows="4" placeholder="177.68.243.240&#10;2804:14c::/32"
                          class="{{ $input }} font-mono">{{ old('enderecos', implode("\n", $own['edited'])) }}</textarea>
                @error('enderecos')<span class="mt-1 block text-xs text-red-300">{{ $message }}</span>@enderror
            </label>
            <button class="rounded-lg border border-ink-800 px-4 py-2 text-sm text-ink-200 hover:border-accent">Salvar a lista</button>
        </form>
        <form method="POST" action="{{ route('admin.accesses.own.mine') }}" class="mt-3">
            @csrf
            {{-- O IP vai na mensagem depois do clique: o de casa do dono muda, e a página não precisa mostrá-lo antes. --}}
            <button class="text-xs text-accent hover:underline">Adicionar o meu IP atual</button>
        </form>
    </section>
@endsection
