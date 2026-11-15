{{--
    O Parar de uma execução, igual na faixa, na lista e na página dela (canvas: Execucao-ao-vivo e Estados-gerais).
    Confirma com o foco em "Parar agora": Enter para, Esc cancela. Parar nunca faz mal, então a confirmação usa o
    tom de sempre, não o de ação destrutiva, e a rota não tem limite de tentativas. Espera $run; $form e $class
    (opcionais) completam o formulário e o botão; com $short, o rótulo no celular é só "Parar" (situação 11).
--}}
<form method="POST" action="{{ route('alerts.tests.runs.stop', ['execucao' => $run->uuid]) }}" class="{{ $form ?? '' }}" data-confirm-ok="Parar agora" data-confirm-focus="ok"
      data-confirm="Parar o teste em {{ $run->domain }}? Nenhuma requisição nova sai a partir de agora e tudo para em até 30 segundos.">
    @csrf
    <button class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg bg-red-300 px-4 text-sm font-semibold text-red-950 hover:bg-red-200 {{ $class ?? '' }}">
        <svg aria-hidden="true" class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
        @if (! empty($short))
            <span class="sm:hidden">Parar</span><span class="hidden sm:inline">Parar teste</span>
        @else
            Parar teste
        @endif
    </button>
</form>
