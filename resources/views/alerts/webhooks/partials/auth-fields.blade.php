{{--
    As opções de autenticação do webhook (cadastro e troca do segredo). Recebe $current: o modo marcado
    (gerar, escolher ou nenhuma). O campo do segredo só aparece com "Escolher o valor" (regra no app.css).
--}}
@php
    $field = 'min-h-11 rounded-lg border border-ink-700 bg-ink-850 px-3 text-sm text-white focus:border-accent';
    $chosen = old('autenticacao', $current);
    $options = [
        'gerar' => ['Gerar um valor aleatório', 'Recomendado. 32 bytes aleatórios, mostrados uma vez só, logo depois de salvar.'],
        'escolher' => ['Escolher o valor', 'De 32 a 128 caracteres: letras, números e . _ ~ + / = -'],
        'nenhuma' => ['Sem autenticação', 'As notificações saem sem os cabeçalhos: qualquer um que souber a URL pode se passar pelo Security Monitor.'],
    ];
@endphp
<fieldset class="space-y-2" data-webhook-auth>
    <legend class="mb-1 text-sm text-ink-400">Autenticação (cabeçalhos <span class="font-mono text-ink-200">Security-Monitor-Notification</span> e <span class="font-mono text-ink-200">Security-Monitor-Signature</span>)</legend>
    @foreach ($options as $value => [$label, $hint])
        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-800 px-3 py-2.5 has-[:checked]:border-accent/60 has-[:checked]:bg-accent/5">
            <input type="radio" name="autenticacao" value="{{ $value }}" @checked($chosen === $value) class="mt-1 size-4 shrink-0 accent-accent">
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-white">{{ $label }}</span>
                <span class="block text-xs text-ink-400">{{ $hint }}</span>
            </span>
        </label>
    @endforeach
    <div class="flex-col gap-1.5 pt-1" data-secret-field>
        <label for="segredo" class="text-sm text-ink-400">Valor do segredo</label>
        <input id="segredo" name="segredo" type="password" autocomplete="new-password" spellcheck="false" maxlength="128"
               class="{{ $field }} w-full font-mono text-xs">
    </div>
    @error('autenticacao') <p class="text-sm text-red-400">{{ $message }}</p> @enderror
    @error('segredo') <p class="text-sm text-red-400">{{ $message }}</p> @enderror
</fieldset>
