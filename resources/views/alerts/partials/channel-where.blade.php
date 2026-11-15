{{--
    O canal da empresa (E5a2): onde ele recebe e, no Telegram e no Discord, que a conversa particular com o bot não vale
    para a empresa (P1). $where: ['sector' => nome ou nulo nos gerais], nulo na conta pessoal; $private: 'telegram' | 'discord'.
--}}
@if ($where)
    <p class="mt-2 text-sm text-ink-200" data-where>
        {{ $where['sector'] === null
            ? 'Este canal é dos canais gerais: recebe os avisos do que é da empresa toda (sem setor).'
            : "Este canal é do setor {$where['sector']}: recebe só os avisos do que é do setor {$where['sector']}." }}
    </p>
    @if ($private ?? null)
        <p class="mt-1 text-xs text-ink-400">
            Os avisos da empresa vão para um lugar da equipe, nunca para a conversa particular de uma pessoa: assim, se alguém sai da empresa, os avisos não vão junto.
            {{ $private === 'telegram' ? 'Use um grupo do Telegram: a conversa particular com o bot não vale para a empresa.' : 'Use um canal de servidor do Discord: a mensagem direta com o bot não vale para a empresa.' }}
        </p>
    @endif
@endif
