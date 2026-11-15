{{-- Liga e desliga a etapa. Desligada, ela é pulada e a IA não é chamada. --}}
<form method="POST" action="{{ route('admin.prompts.toggle', $stage['slug']) }}"
      data-confirm="{{ $stage['on'] ? 'Desligar “'.$stage['label'].'”? '.$stage['off'] : 'Ligar “'.$stage['label'].'”? A próxima vez que a etapa rodar, a IA é chamada.' }}"
      data-confirm-ok="{{ $stage['on'] ? 'Desligar' : 'Ligar' }}">
    @csrf
    <button type="submit"
            class="inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs {{ $stage['on'] ? 'border-emerald-800/70 bg-emerald-950/40 text-emerald-300 hover:border-emerald-600' : 'border-ink-700 bg-ink-850 text-ink-400 hover:border-ink-400' }}"
            title="{{ $stage['on'] ? 'Clique para desligar' : 'Clique para ligar' }}">
        <span class="size-2 rounded-full {{ $stage['on'] ? 'bg-emerald-400' : 'bg-ink-700' }}"></span>
        {{ $stage['on'] ? 'Ligada' : 'Desligada' }}
    </button>
</form>
