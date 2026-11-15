{{--
    Caixa de diálogo padrão do site. Toda confirmação ou aviso passa por aqui —
    nunca por alert()/confirm()/prompt() do navegador. Fica escondida no layout e
    é controlada por window.dialog (resources/js/app.js):

        await dialog.confirm({ title: 'Excluir?', message: '…', okLabel: 'Excluir', tone: 'danger' })  // true/false
        await dialog.alert({ title: 'Pronto', message: '…' })

    Em formulários basta data-confirm="Pergunta? Explicação." (ver app.js).
--}}
<div data-dialog hidden class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
    <div data-dialog-backdrop class="absolute inset-0 bg-ink-950/80 backdrop-blur-sm"></div>

    <div role="alertdialog" aria-modal="true" aria-labelledby="dialog-title" aria-describedby="dialog-message"
         class="relative w-full max-w-md rounded-xl border border-ink-800 bg-ink-900 p-5 shadow-2xl shadow-black/50">
        <h2 id="dialog-title" data-dialog-title class="text-base font-semibold text-white"></h2>
        <p id="dialog-message" data-dialog-message class="mt-2 text-sm leading-6 text-ink-400"></p>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" data-dialog-cancel
                    class="min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">Cancelar</button>
            <button type="button" data-dialog-ok class="min-h-11 rounded-lg px-4 text-sm font-semibold"></button>
        </div>
    </div>
</div>
