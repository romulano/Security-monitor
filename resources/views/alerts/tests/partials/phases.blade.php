{{--
    As 7 fases de uma execução, com o texto do convite (Testes-vazio) ou o curto do cartão da Integração.
    Opcional $short = true.
--}}
@php
    $phases = [
        ['Reconhecimento', 'DNS e certificados públicos; depois, a lista de alvos fechada, o vínculo de cada servidor e as portas dos que são dedicados ao seu domínio.', 'DNS, certificados, o vínculo e as portas dos servidores dedicados'],
        ['Mapeamento', 'Navegação só de leitura, sem enviar formulário, com no máximo 2 requisições por segundo.', 'o que cada site mostra, só lendo'],
        ['Hipóteses', \App\Services\Pentest\PentestRunView::hypothesesText(), 'o que pode estar vulnerável, e por quê'],
        ['Testes', 'Só o catálogo v1: 35 itens em 7 categorias, nada destrutivo.', 'só itens do catálogo, sem causar dano'],
        ['Resultados', 'O que deu positivo, com a prova mascarada antes de gravar.', 'cada candidato com a prova mascarada'],
        ['Revalidação', 'Uma 2ª prova, por outro método e 10 minutos depois, e um controle negativo.', 'uma segunda prova antes de virar achado'],
        ['Resultado final', 'Relatório com a prova, a correção e o que não foi testado.', 'o que foi testado, o que não foi e por quê'],
    ];
    $short ??= false;
@endphp

<ol class="{{ $short ? 'grid grid-cols-[repeat(auto-fit,minmax(min(170px,100%),1fr))] gap-2' : 'grid grid-cols-[repeat(auto-fit,minmax(min(250px,100%),1fr))] gap-px overflow-hidden rounded-2xl border border-ink-800 bg-ink-800' }}">
    @foreach ($phases as $i => [$name, $long, $brief])
        <li class="{{ $short ? 'rounded-lg border border-ink-800 bg-ink-950 px-3 py-2.5' : 'bg-ink-900 px-6 pt-6 pb-6' }}">
            <p class="font-mono text-xs font-semibold tracking-wide text-accent">0{{ $i + 1 }} <span class="text-ink-400">/ 07</span></p>
            @if ($short)
                <p class="mt-1 text-sm font-semibold text-white">{{ $name }}</p>
                <p class="mt-0.5 text-xs text-ink-400">{{ $brief }}</p>
            @else
                <h3 class="mt-2.5 text-lg font-bold text-white">{{ $name }}</h3>
                <p class="mt-2 text-sm leading-[22px] text-ink-200">{{ $long }}</p>
            @endif
        </li>
    @endforeach
</ol>
