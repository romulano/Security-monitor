{{--
    O setor de um monitoramento de empresa e o "Trocar o setor" (Radar, GitHub, Notícias). Quem está no setor vê; sem setor,
    a empresa toda. Quem configura troca: quem administra a empresa, para qualquer setor ou "sem setor"; quem responde por
    setores, entre os seus. A pergunta (e o tom) da caixa de confirmação vem da opção escolhida (select[data-confirm-choice]);
    a atual fica marcada e desabilitada. O select é obrigatório: "sem setor" vai como "sem".
    Parâmetros: $item (a raiz, com sector_id, sector e urlParams), $action (a rota do PUT), $subject (a 1ª menção, com o
    nome: "o Radar de acme.com.br"), $short ("este Radar"; o parcial deriva "deste Radar") e $things ("os domínios achados");
    da tela, $sectorChoices, $sectorless e $mutedIn. Opcional: $blocked ([setor => quantos]), os setores para onde o item não
    pode ir (o domínio da empresa que deixaria alerta ou Radar de outro setor sem a posse): a opção fica desabilitada, com o porquê.
--}}
@php
    $of = preg_replace('/^o /u', 'do ', $subject);
    $ofShort = preg_replace(['/^este /u', '/^esta /u'], ['deste ', 'desta '], $short);
    $current = $item->sector?->name;
    $ask = fn (?string $to) => match (true) {
        $to === null => "Deixar {$subject} sem setor? Toda a empresa passa a ver {$short} e {$things}.",
        $current !== null => "Trocar o setor {$of} para {$to}? Quem está só em {$current} deixa de ver {$short} e {$things}.",
        default => "Trocar o setor {$of} para {$to}? A partir daí, só quem está em {$to} (e quem administra a empresa) vê {$short} e {$things}.",
    };
    // Até a separação dos canais por setor, o aviso sai pelos canais de quem criou (Red Team da E2d1).
    $mute = fn (string $to) => " Atenção: quem criou {$short} não está em {$to}. Até os canais serem separados por setor, os avisos {$ofShort} param.";
    $id = 'setor-'.$item->getTable().'-'.$item->getKey();
    $blocked ??= [];
@endphp
<div class="flex flex-wrap items-center gap-2 text-sm">
    <span class="text-ink-400">
        Setor: <span class="text-ink-200">{{ $current ?? 'sem setor (toda a empresa vê)' }}</span>
    </span>
    @if ($sectorChoices->isNotEmpty() || $sectorless)
        <details class="w-full sm:w-auto">
            <summary class="cursor-pointer text-xs text-accent hover:underline">Trocar o setor</summary>
            <form method="POST" action="{{ $action }}" class="mt-2 flex flex-wrap gap-2" data-confirm="Trocar o setor {{ $of }}?" data-confirm-ok="Trocar">
                @csrf
                @method('PUT')
                <label class="sr-only" for="{{ $id }}">Novo setor {{ $of }}</label>
                <select id="{{ $id }}" name="setor" required data-confirm-choice
                        class="min-h-11 min-w-0 flex-1 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent">
                    <option value="" disabled @selected(true) hidden>Escolha o setor…</option>
                    @if ($sectorless)
                        <option value="sem" @disabled($item->sector_id === null)
                                data-confirm="{{ $ask(null) }}" data-confirm-ok="Deixar sem setor">Sem setor (toda a empresa vê){{ $item->sector_id === null ? ' (atual)' : '' }}</option>
                    @endif
                    @foreach ($sectorChoices as $choice)
                        @php
                            $muted = in_array($choice->id, $mutedIn, true);
                            $blocking = $blocked[$choice->id] ?? 0;
                        @endphp
                        <option value="{{ $choice->id }}" @disabled($item->sector_id === $choice->id || $blocking > 0)
                                data-confirm="{{ $ask($choice->name).($muted ? $mute($choice->name) : '') }}" data-confirm-ok="Trocar para {{ $choice->name }}"
                                @if ($muted) data-confirm-tone="danger" @endif>{{ $choice->name }}{{ $item->sector_id === $choice->id ? ' (atual)' : '' }}{{ $blocking > 0 && $item->sector_id !== $choice->id ? ' — indisponível: um alerta ou Radar de outro setor usa este domínio' : '' }}</option>
                    @endforeach
                </select>
                <button class="min-h-11 rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-accent">Trocar</button>
                @if (array_diff(array_keys(array_filter($blocked)), [$item->sector_id]) !== [])
                    <p class="w-full text-xs text-ink-400">Alguns setores estão indisponíveis porque alertas ou Radares de outros setores usam este domínio. Troque o setor deles antes.</p>
                @endif
            </form>
        </details>
    @endif
</div>
