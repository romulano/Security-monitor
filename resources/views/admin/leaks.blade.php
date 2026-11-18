@extends('layouts.admin')

@section('title', 'Vazamentos')

@section('content')
    @php
        $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—';
        $reasons = \App\Models\LeakImportLine::REASONS;

        // Chute só para poupar digitação: o endereço é o campo com esquema, o login é o que tem
        // cara de e-mail, e o que sobra é a senha. O admin corrige o que estiver errado.
        $guess = function (array $fields) {
            $url = $login = null;
            $rest = [];
            foreach ($fields as $field) {
                if ($url === null && preg_match('#^[a-z][a-z0-9+.\-]*://#i', $field) === 1) {
                    $url = $field;
                } elseif ($login === null && str_contains($field, '@')) {
                    $login = $field;
                } else {
                    $rest[] = $field;
                }
            }
            if ($login === null) { $login = array_shift($rest); }
            return ['url' => $url, 'login' => $login ?? '', 'password' => implode(':', $rest)];
        };
    @endphp

    <div class="mb-5">
        <h1 class="text-sm font-semibold text-white">Vazamentos</h1>
        <p class="max-w-3xl text-xs leading-relaxed text-ink-700">
            A carga lê os dumps da pasta <span class="font-mono">storage/app/private/vazamentos</span> e guarda <strong class="text-ink-400">só credencial de empresa</strong>: webmail
            genérico é descartado pela lista desta tela. Quando a linha não prova quem é login e quem é senha — endereço
            no fim e nenhum campo sendo e-mail — ela não é adivinhada: cai na
            <span class="text-amber-300">fila</span> para você decidir. Chutar aqui gravaria senha no lugar de login.
        </p>
    </div>

    <nav class="mb-4 flex flex-wrap gap-1 text-sm">
        @foreach ([
            'fila' => ['Fila', $counts['pending'], true],
            'resolvidas' => ['Resolvidas', $counts['resolved'], false],
            'descartadas' => ['Descartadas', $counts['discarded'], false],
            'genericos' => ['E-mails genéricos', $freeCounts['domains'] + $freeCounts['brands'], false],
            'dumps' => ['Pasta e dumps', null, false],
        ] as $key => [$name, $total, $warn])
            <a href="{{ route('admin.leaks', $key === 'fila' ? [] : ['ver' => $key]) }}"
               class="rounded-md px-2.5 py-1.5 {{ $tab === $key ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-ink-200' }}">
                {{ $name }}
                @if ($total !== null)
                    <span class="font-mono {{ $warn && $total ? 'text-amber-300' : 'text-ink-700' }}">· {{ $total }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    {{-- ---------------------------------------------------------------- Fila, resolvidas, descartadas --}}
    @if ($lines !== null)
        @if ($lines->isEmpty())
            <p class="rounded-lg border border-ink-800 bg-ink-900 p-5 text-sm text-ink-400">
                {{ $tab === 'fila' ? 'Nada na fila: toda linha dos dumps carregados foi lida sozinha.' : 'Nenhuma linha aqui.' }}
            </p>
        @else
            <ul class="space-y-2">
                @foreach ($lines as $line)
                    @php $hint = $guess($line->fields); @endphp
                    <li class="rounded-lg border {{ $tab === 'fila' ? 'border-amber-900/40' : 'border-ink-800' }} bg-ink-900 p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-xs text-ink-700">
                                <span class="text-ink-400">{{ $line->source }}</span> · linha {{ $line->line_no }}
                                @if ($line->country) · país {{ $line->country }} @endif
                                · chegou {{ $when($line->created_at) }}
                            </p>
                            <p class="text-xs {{ $tab === 'fila' ? 'text-amber-300' : 'text-ink-700' }}">
                                {{ $reasons[$line->reason] ?? $line->reason }}
                            </p>
                        </div>

                        <pre class="mt-2 overflow-x-auto rounded bg-ink-950 p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap break-all text-ink-300">{{ $line->raw }}</pre>

                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($line->fields as $i => $field)
                                <span class="rounded bg-ink-850 px-1.5 py-0.5 font-mono text-[11px] text-ink-400">
                                    <span class="text-ink-700">{{ $i + 1 }}·</span> {{ $field }}
                                </span>
                            @endforeach
                        </div>

                        @if ($tab === 'fila')
                            <form method="POST" action="{{ route('admin.leaks.classify', $line) }}" class="mt-3">
                                @csrf
                                <div class="grid gap-2 sm:grid-cols-3">
                                    @foreach ([
                                        ['login', 'login (precisa ser e-mail)', $hint['login']],
                                        ['password', 'senha', $hint['password']],
                                        ['url', 'endereço (opcional)', $hint['url']],
                                    ] as [$field, $label, $value])
                                        <label class="block text-xs text-ink-700">
                                            {{ $label }}
                                            <input type="text" name="{{ $field }}" value="{{ old($field, $value) }}"
                                                   class="mt-1 w-full rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 font-mono text-xs text-ink-200 focus:border-accent focus:outline-none">
                                        </label>
                                    @endforeach
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                                        Gravar credencial
                                    </button>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('admin.leaks.discard', $line) }}" class="mt-2"
                                  data-confirm="Descartar esta linha? Ela sai da fila e não gera credencial. Dá para ver depois em Descartadas.">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-lg border border-ink-800 px-3 py-1.5 text-xs text-ink-400 hover:border-red-800 hover:text-red-300">
                                    Descartar
                                </button>
                            </form>
                        @else
                            <p class="mt-2 text-xs text-ink-700">
                                {{ $line->status === 'resolved' ? 'gravada' : 'descartada' }} por
                                {{ $line->resolver?->name ?? 'alguém que saiu' }} em {{ $when($line->resolved_at) }}
                                @if ($line->leaked_credential_id) · credencial #{{ $line->leaked_credential_id }} @endif
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">{{ $lines->links() }}</div>
        @endif
    @endif

    {{-- ------------------------------------------------------------------------------ E-mails genéricos --}}
    @if ($tab === 'genericos')
        <div class="mb-4 rounded-lg border border-ink-800 bg-ink-900 p-4">
            <h2 class="text-sm font-semibold text-white">Cadastrar</h2>
            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-ink-700">
                <strong class="text-ink-400">Domínio</strong> é exato: <span class="font-mono">gmail.com</span>.
                <strong class="text-ink-400">Marca</strong> casa a marca com qualquer sufixo público — uma linha
                <span class="font-mono">hotmail</span> pega <span class="font-mono">hotmail.com</span>,
                <span class="font-mono">hotmail.co.uk</span> e <span class="font-mono">hotmail.fr</span> de uma vez,
                sem cadastrar os 127 domínios da Microsoft. Marca não pega subdomínio de empresa:
                <span class="font-mono">live.suaempresa.com</span> continua sendo empresa.
            </p>

            <form method="POST" action="{{ route('admin.leaks.free-mail.store') }}" class="mt-3 grid gap-2 sm:grid-cols-[8rem_1fr_1fr_auto] sm:items-end">
                @csrf
                <label class="block text-xs text-ink-700">
                    natureza
                    <select name="kind" class="mt-1 w-full rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 text-xs text-ink-200 focus:border-accent focus:outline-none">
                        <option value="domain" @selected(old('kind') === 'domain')>domínio</option>
                        <option value="brand" @selected(old('kind') === 'brand')>marca</option>
                    </select>
                </label>
                <label class="block text-xs text-ink-700">
                    domínio ou marca
                    <input type="text" name="value" value="{{ old('value') }}" placeholder="gmail.com"
                           class="mt-1 w-full rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 font-mono text-xs text-ink-200 focus:border-accent focus:outline-none">
                </label>
                <label class="block text-xs text-ink-700">
                    observação (opcional)
                    <input type="text" name="note" value="{{ old('note') }}" maxlength="120"
                           class="mt-1 w-full rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 text-xs text-ink-200 focus:border-accent focus:outline-none">
                </label>
                <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Cadastrar</button>
            </form>
        </div>

        @foreach ([['Marcas', $brands, 'marca'], ['Domínios', $domains, 'domínio']] as [$title, $rows, $noun])
            <div class="mb-4">
                <h2 class="mb-2 text-sm font-semibold text-white">{{ $title }} <span class="font-mono text-xs text-ink-700">· {{ $rows->count() }}</span></h2>
                @if ($rows->isEmpty())
                    <p class="rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm text-ink-400">Nenhuma {{ $noun }} cadastrada.</p>
                @else
                    <ul class="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($rows as $row)
                            <li class="flex items-center justify-between gap-2 rounded-lg border border-ink-800 bg-ink-900 px-3 py-2">
                                <span class="min-w-0">
                                    <span class="block truncate font-mono text-xs text-ink-200">{{ $row->value }}</span>
                                    @if ($row->note)<span class="block truncate text-[11px] text-ink-700">{{ $row->note }}</span>@endif
                                </span>
                                <form method="POST" action="{{ route('admin.leaks.free-mail.destroy', $row) }}"
                                      data-confirm="Tirar {{ $row->value }} da lista? A partir da próxima carga, e-mail desse domínio passa a ser guardado como se fosse de empresa.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="shrink-0 text-xs text-ink-700 hover:text-red-400">remover</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @endif

    {{-- ------------------------------------------------------------------------------ Pasta e dumps --}}
    @if ($tab === 'dumps')
        @php
            $kb = function (?int $bytes) {
                if ($bytes === null) { return '—'; }
                foreach (['B', 'kB', 'MB', 'GB'] as $unit) {
                    if ($bytes < 1024 || $unit === 'GB') { return number_format($bytes, $bytes < 10 && $unit !== 'B' ? 1 : 0, ',', '.').' '.$unit; }
                    $bytes /= 1024;
                }
            };
            $marks = [
                'queued' => 'bg-ink-850 text-ink-400', 'running' => 'bg-emerald-950/60 text-emerald-300',
                'done' => 'bg-sky-950/60 text-sky-300', 'failed' => 'bg-red-950/60 text-red-300',
            ];
        @endphp

        {{-- A pasta e a decisão de apagar --}}
        <div class="mb-4 rounded-lg border border-ink-800 bg-ink-900 p-4">
            <h2 class="text-sm font-semibold text-white">A pasta de vazamentos</h2>
            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-ink-700">
                A raiz é sempre <span class="font-mono text-ink-400">storage/app/private/vazamentos</span> e não se muda
                por aqui: copie os dumps para lá. O que você escolhe é a <strong class="text-ink-400">subpasta dentro
                dela</strong> — vazio significa a raiz. A carga olha só os arquivos <span class="font-mono">.txt</span>
                dessa pasta, sem entrar em subpastas.
            </p>

            <form method="POST" action="{{ route('admin.leaks.folder.update') }}" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <label class="block text-xs text-ink-700">
                    subpasta (vazio = raiz)
                    <input type="text" name="prefix" value="{{ old('prefix', $folder['prefix']) }}" placeholder="vazamentos"
                           class="mt-1 w-64 rounded-lg border border-ink-800 bg-ink-850 px-2.5 py-1.5 font-mono text-xs text-ink-200 focus:border-accent focus:outline-none">
                </label>

                <label class="flex cursor-pointer items-center gap-2 rounded-lg border {{ $deletes ? 'border-red-900/60 bg-red-950/20' : 'border-ink-800 bg-ink-850' }} px-3 py-2 text-xs text-ink-300">
                    <input type="checkbox" name="delete_after_import" value="1" @checked($deletes)
                           class="rounded border-ink-700 bg-ink-850 text-accent">
                    apagar o arquivo da pasta depois de carregar
                </label>

                <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar</button>
            </form>

            @if ($deletes)
                <p class="mt-2 rounded border border-red-900/50 bg-red-950/30 px-3 py-2 text-xs text-red-300">
                    Está ligado: o <span class="font-mono">.txt</span> é apagado da pasta depois de entrar no banco.
                    A pasta não tem backup, e apagado não volta. A carga só apaga se tiver lido alguma linha.
                </p>
            @endif
        </div>

        {{-- O que está na pasta agora --}}
        <div class="mb-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-white">
                    Na pasta agora
                    <span class="font-mono text-xs text-ink-700">· {{ $folder['prefix'] === '' ? 'vazamentos/' : 'vazamentos/'.$folder['prefix'].'/' }}</span>
                </h2>
                @if ($folder['ok'])
                    <form method="POST" action="{{ route('admin.leaks.folder.scan') }}"
                          data-confirm="Varrer a pasta e carregar os .txt que faltam?{{ $deletes ? ' ATENÇÃO: apagar-após-carregar está LIGADO, então cada arquivo carregado sai da pasta.' : '' }}">
                        @csrf
                        <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                            Varrer a pasta e carregar
                        </button>
                    </form>
                @endif
            </div>

            @if (! $folder['ok'])
                <div class="rounded-lg border border-red-900/50 bg-red-950/30 p-4 text-sm text-red-300">
                    <p class="font-medium">Não consegui ler a pasta.</p>
                    <p class="mt-1 font-mono text-xs leading-relaxed">{{ $folder['error'] }}</p>
                    <p class="mt-2 text-xs text-ink-400">
                        Confira se <span class="font-mono">storage/app/private/vazamentos</span> existe, é uma pasta
                        e pode ser lida pelo usuário do PHP (no Docker, o <span class="font-mono">www-data</span>).
                    </p>
                </div>
            @elseif ($folder['files'] === [])
                <p class="rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm text-ink-400">
                    Nenhum <span class="font-mono">.txt</span> nessa pasta.
                </p>
            @else
                <ul class="space-y-1">
                    @foreach ($folder['files'] as $file)
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-ink-800 bg-ink-900 px-3 py-2">
                            <span class="min-w-0">
                                <span class="block truncate font-mono text-xs text-ink-200">{{ $file['path'] }}</span>
                                <span class="block text-[11px] text-ink-700">
                                    {{ $kb($file['bytes']) }} · dump <span class="font-mono">{{ $file['source'] }}</span>
                                    @if ($file['status'])
                                        · <span class="rounded px-1 {{ $marks[$file['status']] ?? '' }}">{{ $file['status'] }}</span>
                                    @else
                                        · <span class="text-amber-300">nunca carregado</span>
                                    @endif
                                </span>
                            </span>
                            @if (! $file['busy'])
                                <form method="POST" action="{{ route('admin.leaks.folder.reload') }}"
                                      data-confirm="Carregar {{ $file['path'] }} agora?{{ $file['status'] === 'done' ? ' Ele já foi carregado antes; recarregar não duplica linha.' : '' }}">
                                    @csrf
                                    <input type="hidden" name="path" value="{{ $file['path'] }}">
                                    <button class="shrink-0 rounded-lg border border-ink-800 px-3 py-1.5 text-xs text-ink-200 hover:border-accent">
                                        {{ $file['status'] === 'done' ? 'carregar de novo' : 'carregar' }}
                                    </button>
                                </form>
                            @else
                                <span class="shrink-0 text-xs text-ink-700">na fila</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- O que já rodou --}}
        <div class="mb-4">
            <h2 class="mb-2 text-sm font-semibold text-white">Cargas</h2>
            @if ($dumps->isEmpty())
                <p class="rounded-lg border border-ink-800 bg-ink-900 p-4 text-sm text-ink-400">Nenhuma carga ainda.</p>
            @else
                <ul class="space-y-1">
                    @foreach ($dumps as $dump)
                        <li class="rounded-lg border {{ $dump->status === 'failed' ? 'border-red-900/50' : 'border-ink-800' }} bg-ink-900 px-3 py-2">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="min-w-0 truncate font-mono text-xs text-ink-200">
                                    {{ $dump->path }}
                                    <span class="rounded px-1 text-[10px] {{ $marks[$dump->status] ?? '' }}">{{ $dump->status }}</span>
                                    @if ($dump->removed)<span class="rounded bg-ink-850 px-1 text-[10px] text-ink-400">apagado da pasta</span>@endif
                                </span>
                                <span class="text-[11px] text-ink-700">
                                    {{ $dump->requester?->name ?? 'alguém que saiu' }} · {{ $when($dump->created_at) }}
                                    @if ($dump->finished_at) · terminou {{ $when($dump->finished_at) }} @endif
                                </span>
                            </div>
                            @if ($dump->tally)
                                <p class="mt-1 text-[11px] text-ink-400">
                                    {{ number_format($dump->tally['linhas'] ?? 0, 0, ',', '.') }} linhas ·
                                    <span class="text-emerald-300">{{ number_format($dump->tally['salvas'] ?? 0, 0, ',', '.') }} guardadas</span> ·
                                    {{ number_format($dump->tally['genericas'] ?? 0, 0, ',', '.') }} genéricas ·
                                    {{ number_format($dump->tally['sem_email'] ?? 0, 0, ',', '.') }} sem e-mail ·
                                    <span class="text-amber-300">{{ number_format($dump->tally['humano'] ?? 0, 0, ',', '.') }} para o humano</span> ·
                                    {{ number_format($dump->tally['repetidas'] ?? 0, 0, ',', '.') }} repetidas
                                </p>
                            @endif
                            @if ($dump->error)
                                <p class="mt-1 font-mono text-[11px] text-red-300">{{ $dump->error }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Quanto cada dump rendeu no banco --}}
        @if ($sources->isNotEmpty())
            <h2 class="mb-2 text-sm font-semibold text-white">Credenciais por dump</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-ink-700">
                    <tr class="border-b border-ink-800">
                        <th class="py-2">dump</th>
                        <th class="py-2 text-right">credenciais</th>
                        <th class="py-2 text-right">domínios</th>
                        <th class="py-2 text-right">última carga</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sources as $source)
                        <tr class="border-b border-ink-850">
                            <td class="py-2 font-mono text-xs text-ink-200">{{ $source->source }}</td>
                            <td class="py-2 text-right font-mono text-xs text-ink-400">{{ number_format($source->total, 0, ',', '.') }}</td>
                            <td class="py-2 text-right font-mono text-xs text-ink-400">{{ number_format($source->domains, 0, ',', '.') }}</td>
                            <td class="py-2 text-right text-xs text-ink-700">{{ $when(\Illuminate\Support\Carbon::parse($source->last_at)) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
@endsection
