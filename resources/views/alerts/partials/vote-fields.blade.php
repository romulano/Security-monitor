{{--
    Campos da avaliação de um alerta: o voto e o motivo opcional, que a IA do
    parecer lê para decidir os próximos alertas. Usado no painel "Avaliar" de
    Meus alertas e na página que os links do Slack, Discord e Telegram abrem.
    Espera $match e $id (id único do textarea, para o contador). O monitoramento do
    GitHub usa os mesmos campos (o achado tem as mesmas colunas de avaliação) e
    troca os textos por $legend, $unaffectedHint e $placeholder. $locked (página do
    link assinado): com voto registrado, a outra opção fica desligada.
--}}
@php $max = \App\Services\Alerts\FeedbackNote::MAX; @endphp

<div data-vote-fields>
<fieldset>
    <legend class="text-xs tracking-widest text-ink-400 uppercase">{{ $legend ?? 'Este alerta é' }}</legend>
    <div class="mt-2 grid grid-cols-2 gap-2">
        @foreach (['relevant' => 'Relevante', 'irrelevant' => 'Não é relevante'] as $value => $label)
            <label class="flex min-h-10 cursor-pointer items-center justify-center rounded-md border border-ink-700 px-2 text-sm text-ink-200 hover:border-ink-400 has-checked:border-accent has-checked:bg-accent/10 has-checked:text-white has-focus-visible:outline-2 has-focus-visible:outline-accent has-disabled:cursor-not-allowed has-disabled:opacity-40">
                <input type="radio" name="feedback" value="{{ $value }}" class="sr-only" required @checked($match->feedback === $value)
                       @disabled(($locked ?? false) && $match->feedback !== null && $match->feedback !== $value)>
                {{ $label }}
            </label>
        @endforeach
    </div>
</fieldset>

{{-- Só aparece com "Relevante" marcado (regra em app.css): a notícia importava, mas o cliente já está protegido. --}}
<label data-unaffected class="mt-2 cursor-pointer items-start gap-2.5 rounded-md border border-ink-800 bg-ink-950 px-3 py-2.5 text-sm text-ink-200 has-checked:border-teal-700/70 has-checked:bg-teal-950/40">
    <input type="checkbox" name="unaffected" value="1" @checked($match->feedback_unaffected) class="mt-0.5 size-4 shrink-0 accent-teal-400">
    <span>
        Mas não nos atinge
        <span class="block text-xs leading-5 text-ink-400">{{ $unaffectedHint ?? 'A notícia importa, mas já estamos protegidos (corrigido, sem a versão afetada ou mitigado).' }}</span>
    </span>
</label>

<label class="mt-3 block" for="{{ $id }}">
    <span class="flex items-baseline justify-between text-sm text-ink-200">
        <span>Por quê? <span class="text-ink-400">(opcional)</span></span>
        <span class="font-mono text-xs text-ink-400" data-counter-for="{{ $id }}">{{ mb_strlen((string) $match->feedback_note) }}/{{ $max }}</span>
    </span>
</label>
<textarea id="{{ $id }}" name="note" rows="4" maxlength="{{ $max }}" data-counter
          placeholder="{{ $placeholder ?? 'Ex.: não usamos SUSE, só Ubuntu nos servidores.' }}"
          class="mt-1 w-full resize-none rounded-lg border border-ink-800 bg-ink-950 px-3 py-2 text-sm text-white placeholder:text-ink-700 focus:border-accent">{{ $match->feedback_note }}</textarea>
<p class="mt-1 text-xs leading-5 text-ink-400">
    {{ $noteHint ?? 'A IA lê o seu motivo para decidir os próximos alertas. Não escreva dados pessoais nem o nome da empresa.' }}
</p>
</div>
