{{-- O cabeçalho de Admin → Acessos: o estado da monitoria (a chave fica em Ajustes) e as abas, que levam os filtros junto. --}}
@php
    // Entre as abas vão só os filtros comuns (período, país, IP, nossos), sem os padrões; os da aba (quem, tipo, dono,
    // página, categoria) ficam nela. Os 180 dias são só da ficha do IP, e a ficha abre sempre nos 180 dias: leva só o IP.
    $defaults = ['periodo' => ['24h', '180d'], 'nossos' => ['esconder']];
    $query = collect(request()->only(['periodo', 'pais', 'ip', 'nossos']))
        ->filter(fn ($value, $key) => is_string($value) && $value !== '' && ! in_array($value, $defaults[$key] ?? [], true))
        ->all();
    $tabs = ['admin.accesses' => 'Visão geral', 'admin.accesses.robots' => 'Robôs', 'admin.accesses.origins' => 'Origens', 'admin.accesses.attacks' => 'Ataques']
        + (($query['ip'] ?? '') !== '' ? ['admin.accesses.ip' => 'Por IP'] : [])
        + ['admin.accesses.settings' => 'Ajustes'];
    // A ficha leva só o IP, e Ajustes não tem filtro.
    $params = fn (string $name) => match ($name) {
        'admin.accesses.ip' => ['ip' => $query['ip']],
        'admin.accesses.settings' => [],
        default => $query,
    };
@endphp
<div class="mb-4 flex flex-wrap items-baseline justify-between gap-4">
    <h1 class="text-sm font-semibold text-white">Acessos</h1>
    <a href="{{ route('admin.accesses.settings') }}" class="text-xs {{ $enabled ? 'text-emerald-300' : 'text-ink-400' }} hover:underline"
       title="A chave da monitoria fica em Ajustes">{{ $enabled ? '● gravando' : '○ desligada' }} ›</a>
</div>
{{-- overflow-y-hidden + pb-px: com só overflow-x-auto, o -mb-px da aba ativa vazava 1 px e o navegador punha uma barra de rolagem vertical. --}}
<nav class="mb-6 flex gap-1 overflow-x-auto overflow-y-hidden pb-px border-b border-ink-800 text-sm" aria-label="Abas de Acessos">
    @foreach ($tabs as $name => $title)
        <a href="{{ route($name, $params($name)) }}" @if ($name === $route) aria-current="page" @endif
           @class(['-mb-px border-b-2 px-3 py-2 whitespace-nowrap', 'border-accent text-white' => $name === $route, 'border-transparent text-ink-400 hover:text-ink-200' => $name !== $route])>{{ $title }}</a>
    @endforeach
</nav>
