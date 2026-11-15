{{-- O robô declarado de uma linha de Admin → Acessos: o dono no selo e o tipo ao lado, numa linha só dele. A célula do IP
     quebra em qualquer letra; aqui a quebra fica entre as palavras, e no meio delas só se a coluna não couber (no celular,
     a tabela continua na largura da tela). É o que o robô diz ser no navegador. --}}
<span class="mt-0.5 block font-sans [word-break:normal] [overflow-wrap:anywhere]" title="o que o robô declara no navegador (User-Agent)">
    <span class="rounded bg-amber-950/60 px-1 text-[10px] text-amber-300">{{ $owner ?? 'robô' }}</span>
    <span class="text-[10px] text-ink-700">{{ \App\Support\RobotCatalog::label($kind) }}</span>
</span>
