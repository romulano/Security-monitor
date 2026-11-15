{{--
    Avaliação de um domínio do Radar: a resposta e o motivo opcional, que a IA
    lê para julgar os próximos achados. Usado no detalhe e na página que os
    links do aviso (Slack, Discord, Telegram) abrem. Espera $lookalike e $id.
    $locked (página do link assinado): com resposta registrada, as outras ficam desligadas.
--}}
@php
    $max = \App\Services\Alerts\FeedbackNote::MAX;
    $current = array_search($lookalike->verdict, \App\Services\Lookalike\LookalikeVerdicts::VERDICTS, true) ?: null;
@endphp

<fieldset>
    <legend class="text-xs tracking-widest text-ink-400 uppercase">Este domínio</legend>
    <div class="mt-2 grid grid-cols-3 gap-2">
        @foreach (['golpe' => 'É golpe', 'nosso' => 'É nosso', 'seguro' => 'Não é ameaça'] as $value => $label)
            <label class="flex min-h-10 cursor-pointer items-center justify-center rounded-md border border-ink-700 px-2 text-center text-sm text-ink-200 hover:border-ink-400 has-checked:text-white has-focus-visible:outline-2 has-focus-visible:outline-accent has-disabled:cursor-not-allowed has-disabled:opacity-40 {{ $value === 'golpe' ? 'has-checked:border-red-500 has-checked:bg-red-950/50' : 'has-checked:border-accent has-checked:bg-accent/10' }}">
                <input type="radio" name="acao" value="{{ $value }}" class="sr-only" required @checked($current === $value)
                       @disabled(($locked ?? false) && $current !== null && $current !== $value)>
                {{ $label }}
            </label>
        @endforeach
    </div>
</fieldset>

<label class="mt-3 block" for="{{ $id }}">
    <span class="flex items-baseline justify-between text-sm text-ink-200">
        <span>Por quê? <span class="text-ink-400">(opcional)</span></span>
        <span class="font-mono text-xs text-ink-400" data-counter-for="{{ $id }}">{{ mb_strlen((string) $lookalike->verdict_note) }}/{{ $max }}</span>
    </span>
</label>
<textarea id="{{ $id }}" name="note" rows="3" maxlength="{{ $max }}" data-counter
          placeholder="Ex.: é a loja oficial de produtos da marca, mantida pela agência."
          class="mt-1 w-full resize-none rounded-lg border border-ink-800 bg-ink-950 px-3 py-2 text-sm text-white placeholder:text-ink-700 focus:border-accent">{{ $lookalike->verdict_note }}</textarea>
<p class="mt-1 text-xs leading-5 text-ink-400">A IA lê a sua resposta e o motivo para julgar os próximos domínios parecidos.</p>
